<?php
declare(strict_types=1);

namespace Tests\Engine\App\RelationSave;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
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

class RsDomainAuthorBase extends Model
{
    protected $fieldConf = ['profile' => ['has-one' => [RsProfile::class, 'author']]];
}

class RsDomainAuthor extends RsDomainAuthorBase
{
    protected $table = 'rs_author';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

class RsDomainOwner extends Model
{
    protected $table = 'rs_owner';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

class RsLegacyChildOfDomain extends Model
{
    protected $table = 'rs_child';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128'],
        'owner' => ['belongs-to-one' => [RsDomainOwner::class, 'id']],
    ];
}

class RsDomainChildBase extends Model
{
    protected $fieldConf = ['owner' => ['belongs-to-one' => [RsDomainOwner::class, 'id']]];
}

class RsDomainChild extends RsDomainChildBase
{
    protected $table = 'rs_child';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

class RsLegacyOwner extends Model
{
    protected $table = 'rs_owner';
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128']];
}

class RsLegacyChildOfLegacy extends Model
{
    protected $table = 'rs_child';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128'],
        'owner' => ['belongs-to-one' => [RsLegacyOwner::class, 'id']],
    ];
}

class RsChildOfUntypedField extends Model
{
    protected $table = 'rs_child';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128'],
        'owner' => ['belongs-to-one' => [RsLegacyOwner::class, 'missing']],
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
        ModelDefinitionManager::reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->drop();
        }
        ModelDefinitionManager::reset();
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

    public function test_cross_style_has_one_model_saves(): void
    {
        $a = new RsDomainAuthor();
        $a->name = 'a';
        $this->assertNotFalse($a->save(), json_encode($a->get_last_err_data()));
    }

    public function test_legacy_belongs_to_one_naming_domain_primary_key_saves(): void
    {
        $this->assert_child_saves(new RsLegacyChildOfDomain());
    }

    public function test_domain_belongs_to_one_naming_domain_primary_key_saves(): void
    {
        $this->assert_child_saves(new RsDomainChild());
    }

    public function test_belongs_to_one_resolves_domain_primary_key_type(): void
    {
        $conf = (new RsDomainChild())->getFieldConfiguration();
        $this->assertSame('INT4', $conf['owner']['type']);
    }

    public function test_domain_primary_key_stays_out_of_instance_field_conf(): void
    {
        $this->assertArrayNotHasKey('id', (new RsDomainOwner())->getFieldConfiguration());
    }

    public function test_legacy_belongs_to_one_naming_legacy_primary_key_saves(): void
    {
        $owner = new RsLegacyOwner();
        $owner->name = 'o';
        $this->assertNotFalse($owner->save());
        $child = new RsLegacyChildOfLegacy();
        $child->name = 'c';
        $child->owner = $owner;
        $this->assertNotFalse($child->save(), json_encode($child->get_last_err_data()));
    }

    public function test_relation_to_untyped_target_field_fails_loudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RsLegacyOwner.missing');
        new RsChildOfUntypedField();
    }

    public function test_field_without_type_fails_loudly_on_save(): void
    {
        $m = new RsTypelessField();
        $m->name = 'x';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field "name" has no column type');
        $m->save();
    }

    private function assert_child_saves(Model $child): void
    {
        $owner = new RsDomainOwner();
        $owner->name = 'o';
        $this->assertNotFalse($owner->save(), json_encode($owner->get_last_err_data()));
        $child->name = 'c';
        $child->owner = $owner;
        $this->assertNotFalse($child->save(), json_encode($child->get_last_err_data()));
        $this->assertSame(
            (int) $owner->id,
            (int) $this->db->exec("SELECT owner FROM `{$this->prefix}rs_child` LIMIT 1")[0]['owner']
        );
    }
}
