<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\PrimaryKeyPolicyTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

class VfPkModel extends Model
{
    protected $table = 'vf_pk';
    protected $primary = 'uuid';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['uuid' => $schema->field()->required()->uuid_v4()]);
    }
}

final class PrimaryKeyPolicyTest extends TestCase
{
    private \DB\SQL $db;
    private string $prefix;
    private array $tables = ['vf_defaults', 'vf_unknown', 'vf_cache', 'vf_dyn', 'vf_pk'];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) $this->markTestSkipped('pdo_mysql is required.');
        $this->db = ConnectionManager::instance()->get_db();
        $this->prefix = (string) App::instance()->get('DB_CONFIG.prefix');
        foreach ($this->tables as $t) {
            $this->db->exec("DROP TABLE IF EXISTS `{$this->prefix}{$t}`");
        }
        ModelDefinitionManager::reset();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->tables as $t) {
            $this->db->exec("DROP TABLE IF EXISTS `{$this->prefix}{$t}`");
        }
        App::instance()->set('CACHE', false);
        ModelDefinitionManager::reset();
    }

    // 5. rule on primary key column
    public function test_rule_on_primary_key(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_pk` (uuid VARCHAR(128) NOT NULL PRIMARY KEY, name VARCHAR(64) NULL)");
        $m = new VfPkModel();
        $this->assertIsArray($m->getFieldConfiguration());
        $this->assertSame(false, $m->save());
        $m->uuid = 'invalid';
        $this->assertSame(false, $m->save());
        $m->uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->assertNotFalse($m->save());
        $m->load(['uuid = ?', $m->uuid]);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $m->uuid);
        $m->name = 'updated';
        $this->assertNotFalse($m->save());
    }

    public function test_auto_increment_primary_key_policy_is_rejected(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_pk` (uuid INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL)");
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('auto-increment primary key');
        new VfPkModel();
    }
}
