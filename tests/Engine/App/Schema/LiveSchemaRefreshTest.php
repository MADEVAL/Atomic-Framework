<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\LiveSchemaRefreshTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

class VfCacheModel extends Model
{
    protected $table = 'vf_cache';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

final class LiveSchemaRefreshTest extends TestCase
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

    // 3. reset() after ALTER still sees stale structure when F3 CACHE is on
    public function test_reset_with_f3_cache_on(): void
    {
        $dir = sys_get_temp_dir() . '/vf_cache_' . uniqid() . '/';
        @mkdir($dir);
        App::instance()->set('CACHE', 'folder=' . $dir);
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_cache` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $before = (new VfCacheModel())->getFieldConfiguration()['name']['max_length'] ?? null;
        $this->db->exec("ALTER TABLE `{$this->prefix}vf_cache` MODIFY name VARCHAR(80) NOT NULL");
        ModelDefinitionManager::reset();
        $after = (new VfCacheModel())->getFieldConfiguration()['name']['max_length'] ?? null;
        $this->assertSame(64, $before);
        $this->assertSame(80, $after);
        $previous = ModelDefinitionManager::instance();
        $this->assertSame('varchar(80)', (new VfCacheModel())->getMapper()->schema()['name']['type']);
        $this->db->exec("ALTER TABLE `{$this->prefix}vf_cache` MODIFY name VARCHAR(96) NOT NULL");
        ModelDefinitionManager::swap(ModelDefinitionManager::with(new \Engine\Atomic\App\Schema\Structure\LiveSchemaSource()));
        $this->assertSame(96, (new VfCacheModel())->getFieldConfiguration()['name']['max_length']);
        $this->assertSame('varchar(96)', (new VfCacheModel())->getMapper()->schema()['name']['type']);
        $this->db->exec("ALTER TABLE `{$this->prefix}vf_cache` MODIFY name VARCHAR(112) NOT NULL");
        ModelDefinitionManager::swap($previous);
        $this->assertSame(112, (new VfCacheModel())->getFieldConfiguration()['name']['max_length']);
    }

    public function test_reset_with_f3_cache_off(): void
    {
        App::instance()->set('CACHE', false);
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_cache` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $this->assertSame(64, (new VfCacheModel())->getFieldConfiguration()['name']['max_length']);
        $this->db->exec("ALTER TABLE `{$this->prefix}vf_cache` MODIFY name VARCHAR(80) NOT NULL");
        ModelDefinitionManager::reset();
        $this->assertSame(80, (new VfCacheModel())->getFieldConfiguration()['name']['max_length']);
    }
}
