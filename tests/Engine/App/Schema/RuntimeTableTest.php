<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\RuntimeTableTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

class VfDynamicModel extends Model
{
    public function __construct(string $table)
    {
        $this->table = $table;
        parent::__construct();
    }
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([]);
    }
}

final class RuntimeTableTest extends TestCase
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

    // 4. table assigned in constructor is ignored
    public function test_runtime_table(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_dyn` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL)");
        $m = new VfDynamicModel('vf_dyn');
        $this->assertArrayHasKey('name', $m->getFieldConfiguration());
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_cache` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $other = new VfDynamicModel('vf_cache');
        $this->assertSame(128, $m->getFieldConfiguration()['name']['max_length']);
        $this->assertSame(64, $other->getFieldConfiguration()['name']['max_length']);
        $this->assertSame(128, (new VfDynamicModel($this->prefix . 'vf_dyn'))->getFieldConfiguration()['name']['max_length']);
    }
}
