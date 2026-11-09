<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;
use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\App;
use PHPUnit\Framework\TestCase;
class BigUnsignedLegacyModel extends Model
{
    protected $table = 'big_unsigned_regression';
    protected $fieldConf = ['amount' => ['type' => 'INT8', 'nullable' => true], 'flag' => ['type' => 'BOOLEAN', 'nullable' => true]];
}
class BigUnsignedDomainModel extends Model
{
    protected $table = 'big_unsigned_regression';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition { return $schema->define([]); }
}
final class BigUnsignedStorageTest extends TestCase
{
    private \DB\SQL $db;
    private string $table;
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) $this->markTestSkipped('pdo_mysql required');
        class_exists('DB\Cortex\Schema\Schema');
        $this->db = ConnectionManager::instance()->get_db();
        $this->table = App::instance()->get('DB_CONFIG.prefix').'big_unsigned_regression';
        $this->db->exec('DROP TABLE IF EXISTS `'.$this->table.'`');
        $this->db->exec('CREATE TABLE `'.$this->table.'` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, amount BIGINT UNSIGNED NULL, flag BIT(1) NOT NULL DEFAULT 0)');
        ModelDefinitionManager::reset();
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) $this->db->exec('DROP TABLE IF EXISTS `'.$this->table.'`');
        ModelDefinitionManager::reset();
    }
    public function test_unchanged_dry_legacy_mapper_does_not_insert_defaults(): void
    {
        $mapper = new \Engine\Atomic\App\Schema\Structure\DefaultSqlMapper($this->db, $this->table, null, 0, false);
        $mapper->insert();
        $this->assertSame(true, $mapper->dry());
        $this->assertSame(0, (int) $this->db->exec('SELECT COUNT(*) AS n FROM `'.$this->table.'`')[0]['n']);
    }
    public function test_legacy_bit_one_writes_numeric_boolean_values(): void
    {
        $m = new BigUnsignedLegacyModel();
        foreach ([true, false, true] as $value) {
            $m->flag = $value;
            $m->save();
            $this->assertSame((int) $value, (int) $this->db->exec('SELECT flag + 0 AS n FROM `'.$this->table.'` WHERE id = ?', $m->id)[0]['n']);
            $loaded = new BigUnsignedLegacyModel();
            $loaded->load(['id = ?', $m->id]);
            $this->assertSame($value, $loaded->flag);
        }
    }
    public static function models(): array { return [[BigUnsignedLegacyModel::class], [BigUnsignedDomainModel::class]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_overflowing_generated_primary_key_remains_exact(string $class): void
    {
        $this->db->exec('ALTER TABLE `'.$this->table.'` AUTO_INCREMENT = 9223372036854775808');
        $m = new $class();
        $m->amount = 5;
        $m->save();
        $this->assertSame('9223372036854775808', $m->id);
        $this->assertSame(5, $m->amount);
        $this->assertSame('9223372036854775808', $m->cast()['_id']);
        $m->amount = '18446744073709551615';
        $m->save();
        $loaded = new $class();
        $loaded->load(['id = ?', '9223372036854775808']);
        $this->assertSame('9223372036854775808', $loaded->id);
        $this->assertSame('18446744073709551615', $loaded->amount);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_normal_values_keep_integer_types_after_save_load_and_find(string $class): void
    {
        $m = new $class();
        $m->amount = '5';
        $m->save();
        $this->assertSame(1, $m->id);
        $this->assertSame(5, $m->amount);
        $this->assertSame(1, $m->cast()['_id']);
        $this->assertSame(5, $m->cast()['amount']);
        $loaded = new $class();
        $loaded->load(['id = ?', 1]);
        $this->assertSame(1, $loaded->id);
        $this->assertSame(5, $loaded->amount);
        $this->assertSame(5, $loaded->cast()['amount']);
        $found = (new $class())->find(['id = ?', 1])[0];
        $this->assertSame(1, $found->id);
        $this->assertSame(5, $found->amount);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_maximum_and_normal_values_round_trip_on_insert_and_update(string $class): void
    {
        $m = new $class();
        foreach (['18446744073709551615', '9223372036854775808', (string) PHP_INT_MAX, '42', '18446744073709551615'] as $value) {
            $m->amount = $value;
            $this->assertNotFalse($m->save());
            $row = $this->db->exec('SELECT amount FROM `'.$this->table.'` WHERE id = ?', $m->id)[0];
            $this->assertSame($value, (string) $row['amount']);
            $loaded = new $class();
            $loaded->load(['id = ?', $m->id]);
            $expected = strlen($value) < strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) <= 0) ? (int) $value : $value;
            $this->assertSame($expected, $loaded->amount);
            $this->assertSame($expected, $m->amount);
            $this->assertSame($expected, $loaded->cast()['amount']);
        }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_float_input_throws_before_write(string $class): void
    {
        try {
            $m = new $class();
            $m->amount = 18446744073709551615.0;
            $m->save();
            $this->fail('Float input must throw before precision can be lost.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('amount', $e->getMessage());
            $this->assertStringContainsString('64-bit signed', $e->getMessage());
            $this->assertStringContainsString('numeric string', $e->getMessage());
            $this->assertStringContainsString('bcmath or gmp', $e->getMessage());
            $this->assertSame(0, (int) $this->db->exec('SELECT COUNT(*) AS n FROM `'.$this->table.'`')[0]['n']);
        }
    }
}
