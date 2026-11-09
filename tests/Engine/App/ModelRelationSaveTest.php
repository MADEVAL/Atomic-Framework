<?php
declare(strict_types=1);

namespace Tests\Engine\App\RelationSave;

use Engine\Atomic\App\Model;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

if (!defined('ATOMIC_START')) exit;

class RsProfile extends Model
{
    protected $table = 'rs_profile';
    protected $fieldConf = ['bio' => ['type' => 'VARCHAR128'], 'author' => ['type' => 'INT4']];
}

class RsLegacyAuthor extends Model
{
    protected $table = 'rs_author';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128'],
        'profile' => ['has-one' => [RsProfile::class, 'author']],
    ];
}

class RsTypelessField extends Model
{
    protected $table = 'rs_child';
    protected $fieldConf = ['name' => ['nullable' => false]];
}

final class ModelRelationSaveTest extends TestCase
{
    private const TABLES = ['rs_author', 'rs_profile', 'rs_owner', 'rs_child'];
    private \DB\SQL $db;
    private string $prefix;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is required.');
        }
        $this->db = ConnectionManager::instance()->get_db();
        $this->prefix = (string) App::instance()->get('DB_CONFIG.prefix');
        $this->drop();
        $p = $this->prefix;
        $this->db->exec("CREATE TABLE `{$p}rs_author` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL)");
        $this->db->exec("CREATE TABLE `{$p}rs_profile` (id INT AUTO_INCREMENT PRIMARY KEY, bio VARCHAR(128) NULL, author INT NULL)");
        $this->db->exec("CREATE TABLE `{$p}rs_owner` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)");
        $this->db->exec("CREATE TABLE `{$p}rs_child` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, owner INT NULL)");
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->drop();
        }
    }

    private function drop(): void
    {
        foreach (self::TABLES as $t) {
            $this->db->exec("DROP TABLE IF EXISTS `{$this->prefix}{$t}`");
        }
    }

    public function test_legacy_has_one_model_saves(): void
    {
        $a = new RsLegacyAuthor();
        $a->name = 'a';
        $this->assertNotFalse($a->save(), json_encode($a->get_last_err_data()));
    }

    public function test_field_without_type_fails_loudly_on_save(): void
    {
        $m = new RsTypelessField();
        $m->name = 'x';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field "name" has no column type');
        $m->save();
    }
}
