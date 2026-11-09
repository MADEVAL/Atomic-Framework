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
final class ColumnFamilyIntegrationTest extends TestCase
{
    public function test_sqlite_domain_affinities_save_and_load(): void
    {
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('pdo_sqlite required');
        $manager = ConnectionManager::instance();
        $property = new \ReflectionProperty($manager, 'mysql_connections');
        $original = $property->getValue($manager);
        $prefix = App::instance()->get('DB_CONFIG.prefix');
        $db = new \DB\SQL('sqlite::memory:');
        $db->exec('CREATE TABLE family_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR(2), quantity CUSTOMNUMERIC, bytes BLOB)');
        $property->setValue($manager, ['default'=>$db]);
        App::instance()->set('DB_CONFIG.prefix', '');
        ModelDefinitionManager::reset();
        try {
            $m = new FamilyProbeModel();
            $this->assertSame('text', $m->getFieldConfiguration()['label']['column_kind']);
            $this->assertArrayNotHasKey('max_length', $m->getFieldConfiguration()['label']);
            $m->label = 'longer than two'; $m->quantity = 1.25; $m->bytes = "a\0b";
            $this->assertNotFalse($m->save());
            $loaded = new FamilyProbeModel(); $loaded->load(['id = ?', $m->id]);
            $this->assertSame('longer than two', $loaded->label);
            $this->assertSame("a\0b", $loaded->bytes);
            $this->assertSame(1.25, (float)$loaded->quantity);
        } finally {
            ModelDefinitionManager::reset();
            $property->setValue($manager, $original);
            App::instance()->set('DB_CONFIG.prefix', $prefix);
        }
    }

    public function test_mysql_extra_families_save_and_load(): void
    {
        if (!extension_loaded('pdo_mysql')) $this->markTestSkipped('pdo_mysql required');
        $db = ConnectionManager::instance()->get_db();
        $table = App::instance()->get('DB_CONFIG.prefix') . 'family_probe';
        $db->exec("DROP TABLE IF EXISTS `$table`");
        $db->exec("CREATE TABLE `$table` (id INT AUTO_INCREMENT PRIMARY KEY, bytes BINARY(16), flag BIT(1), choices SET('a','b'), ratio FLOAT(7,4))");
        ModelDefinitionManager::reset();
        try {
            $m = new FamilyProbeModel();
            $m->bytes = str_repeat('x',16); $m->flag = true; $m->choices = 'a,b'; $m->ratio = 123.4567;
            $this->assertNotFalse($m->save());
            $loaded = new FamilyProbeModel(); $loaded->load(['id = ?', $m->id]);
            $this->assertSame(str_repeat('x',16), $loaded->bytes);
            $this->assertSame(true, $loaded->flag);
            $this->assertSame('a,b', $loaded->choices);
            $this->assertSame('123.4567', number_format((float)$loaded->ratio,4,'.',''));
            $loaded->bytes = str_repeat('x',17);
            $this->assertSame(false, $loaded->save());
        } finally { $db->exec("DROP TABLE `$table`"); ModelDefinitionManager::reset(); }
    }
}
class FamilyProbeModel extends Model
{
    protected $table = 'family_probe';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition { return $schema->define([]); }
}
