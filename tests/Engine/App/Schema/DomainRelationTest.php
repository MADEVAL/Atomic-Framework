<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\DomainRelationTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

function pivot(): string
{
    return App::instance()->get('DB_CONFIG.prefix') . 'dr_user_teams';
}

// ─── domain models: every relation shape ─────────────────────────

class DrUser extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([
            'name' => $schema->field()->required(),
            'posts' => $schema->field()->has_many(DrPost::class, 'author'),
            'profile' => $schema->field()->has_one(DrProfile::class, 'user'),
            'teams' => $schema->field()->has_many(DrTeam::class, 'members')->pivot(pivot()),
            'tags' => $schema->field()->belongs_to_many(DrTag::class),
        ]);
    }
}

class DrPost extends Model
{
    protected $table = 'dr_post';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([
            'author' => $schema->field()->belongs_to_one(DrUser::class),
            'author_uuid' => $schema->field()->belongs_to_one(DrUser::class, 'uuid'),
        ]);
    }
}

class DrProfile extends Model
{
    protected $table = 'dr_profile';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['user' => $schema->field()->belongs_to_one(DrUser::class)]);
    }
}

class DrTeam extends Model
{
    protected $table = 'dr_team';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['members' => $schema->field()->has_many(DrUser::class, 'teams')->pivot(pivot())]);
    }
}

class DrTag extends Model
{
    protected $table = 'dr_tag';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['label' => $schema->field()->required()]);
    }
}

/** Self-referencing m:m with the pivot table name computed by Cortex. */
class DrPeer extends Model
{
    protected $table = 'dr_peer';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['friends' => $schema->field()->has_many(DrPeer::class, 'friends')->pivot()]);
    }
}

// ─── hand-written legacy equivalents (same tables, same targets) ─

class LgUser extends Model
{
    protected $table = 'dr_user';
    protected $fieldConf = [
        'name' => ['type' => 'VARCHAR128', 'required' => true],
        'posts' => ['has-many' => [DrPost::class, 'author']],
        'profile' => ['has-one' => [DrProfile::class, 'user']],
        'tags' => ['belongs-to-many' => DrTag::class],
    ];
}

class LgUserTeams extends Model
{
    protected $table = 'dr_user';
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128']];

    public function __construct()
    {
        $this->fieldConf['teams'] = ['has-many' => [DrTeam::class, 'members', pivot()]];
        parent::__construct();
    }

    public function collect_field_conf(): array
    {
        return $this->fieldConf;
    }
}

class LgPost extends Model
{
    protected $table = 'dr_post';
    protected $fieldConf = [
        'author' => ['belongs-to-one' => DrUser::class],
        'author_uuid' => ['belongs-to-one' => [DrUser::class, 'uuid']],
    ];
}

class LgPeer extends Model
{
    protected $table = 'dr_peer';
    protected $fieldConf = ['friends' => ['has-many' => [DrPeer::class, 'friends']]];
}

// ─── verifier fixtures (bad declarations) ────────────────────────

class BadInverseOnManyToMany extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['teams' => $schema->field()->has_many(DrTeam::class, 'members')]);
    }
}

class BadPivotOnInverse extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['posts' => $schema->field()->has_many(DrPost::class, 'author')->pivot()]);
    }
}

class BadMissingTargetField extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['posts' => $schema->field()->has_many(DrPost::class, 'writer')]);
    }
}

class BadBackClass extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['posts' => $schema->field()->has_many(DrProfile::class, 'user')]);
    }
}

class BadHasOneTarget extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['profile' => $schema->field()->has_one(DrProfile::class, 'bio')]);
    }
}

class BadPivotMismatch extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['teams' => $schema->field()->has_many(DrSquadNamedPivot::class, 'members')->pivot()]);
    }
}

class DrSquadNamedPivot extends Model
{
    protected $table = 'dr_team';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['members' => $schema->field()->has_many(BadPivotMismatch::class, 'teams')->pivot(pivot())]);
    }
}

class BadPivotBackField extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['squads' => $schema->field()->has_many(DrSquadOtherField::class, 'members')->pivot(pivot())]);
    }
}

class DrSquadOtherField extends Model
{
    protected $table = 'dr_team';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['members' => $schema->field()->has_many(BadPivotBackField::class, 'teams')->pivot(pivot())]);
    }
}

class BadBelongsToField extends Model
{
    protected $table = 'dr_post';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['author_uuid' => $schema->field()->belongs_to_one(DrUser::class, 'handle')]);
    }
}

class BadBelongsToManyField extends Model
{
    protected $table = 'dr_user';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['tags' => $schema->field()->belongs_to_many(DrTag::class, 'slug')]);
    }
}

class LegacyRelationParent extends Model
{
    protected $fieldConf = ['author' => ['belongs-to-one' => DrUser::class]];
}

class DomainRedeclaresRelation extends LegacyRelationParent
{
    protected $table = 'dr_post';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['author' => $schema->field()->belongs_to_one(DrUser::class)]);
    }
}

class DomainRelationParent extends Model
{
    protected $table = 'dr_post';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['author' => $schema->field()->belongs_to_one(DrUser::class)]);
    }
}

class LegacyRedeclaresRelation extends DomainRelationParent
{
    protected $fieldConf = ['author' => ['belongs-to-one' => [DrUser::class, 'uuid']]];
}

class DomainPolicyOnInheritedRelation extends DomainRelationParent
{
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['title' => $schema->field()->str_max(64)]);
    }
}

class DrBigChild extends Model
{
    protected $table = 'dr_big_child';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['owner' => $schema->field()->belongs_to_one(DrBigOwner::class)]);
    }
}

class DrBigOwner extends Model
{
    protected $table = 'dr_big_owner';
}

final class DomainRelationTest extends TestCase
{
    private const TABLES = ['dr_user', 'dr_post', 'dr_profile', 'dr_team', 'dr_tag', 'dr_user_teams', 'dr_peer', 'dr_peer__friends', 'dr_big_child', 'dr_big_owner'];
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
        $this->db->exec("CREATE TABLE `{$p}dr_user` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, uuid VARCHAR(64) NULL, tags TEXT NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_post` (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(64) NULL, author INT NULL, author_uuid VARCHAR(64) NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_profile` (id INT AUTO_INCREMENT PRIMARY KEY, bio VARCHAR(64) NULL, user INT NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_team` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_tag` (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(64) NOT NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_user_teams` (id INT AUTO_INCREMENT PRIMARY KEY, members INT NULL, teams INT NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_peer` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_peer__friends` (id INT AUTO_INCREMENT PRIMARY KEY, friends INT NULL, friends_ref INT NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_big_owner` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL)");
        $this->db->exec("CREATE TABLE `{$p}dr_big_child` (id INT AUTO_INCREMENT PRIMARY KEY, owner BIGINT UNSIGNED NULL)");
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

    /** Resolved (post-Cortex) configuration of one field. */
    private function resolved(string $class, string $field): array
    {
        return (new $class())->getFieldConfiguration()[$field];
    }

    /**
     * Every key the legacy conf resolves to must resolve identically from the
     * domain definition; the domain conf may only add the column's live metadata.
     */
    private function assertParity(string $legacy, string $domain, string $field, ?string $table = null): void
    {
        $l = $this->resolved($legacy, $field);
        $d = $this->resolved($domain, $field);
        foreach ($l as $key => $value) {
            $this->assertArrayHasKey($key, $d, "domain conf of $field lacks \"$key\"");
            $this->assertSame($value, $d[$key], "key \"$key\" of $field differs");
        }
        $metadata = $table === null ? [] : array_keys(
            ModelDefinitionManager::instance()->source()->for_table($this->prefix . $table)->field($field)->metadata
        );
        $this->assertSame([], array_values(array_diff(array_keys($d), array_keys($l), $metadata)), "unexpected keys in domain conf of $field");
    }

    // ─── parity with hand-written fieldConf ───────────────────────

    public function test_parity_belongs_to_one_primary_key_form(): void
    {
        $this->assertParity(LgPost::class, DrPost::class, 'author', 'dr_post');
    }

    public function test_parity_belongs_to_one_field_form(): void
    {
        $this->assertParity(LgPost::class, DrPost::class, 'author_uuid', 'dr_post');
    }

    public function test_parity_belongs_to_many(): void
    {
        $this->assertParity(LgUser::class, DrUser::class, 'tags', 'dr_user');
    }

    public function test_parity_has_one(): void
    {
        $this->assertSame($this->resolved(LgUser::class, 'profile'), $this->resolved(DrUser::class, 'profile'));
    }

    public function test_parity_has_many_inverse(): void
    {
        $this->assertSame($this->resolved(LgUser::class, 'posts'), $this->resolved(DrUser::class, 'posts'));
    }

    public function test_parity_has_many_many_to_many_with_pivot_table(): void
    {
        $this->assertSame($this->resolved(LgUserTeams::class, 'teams'), $this->resolved(DrUser::class, 'teams'));
    }

    public function test_parity_has_many_self_referencing_computed_pivot(): void
    {
        $legacy = $this->resolved(LgPeer::class, 'friends');
        $domain = $this->resolved(DrPeer::class, 'friends');
        // isSelf compares the declaring class; the legacy twin is a different class.
        unset($legacy['has-many']['isSelf'], $domain['has-many']['isSelf']);
        $this->assertSame($legacy, $domain);
    }

    public function test_parity_has_many_with_m_m_options(): void
    {
        $legacy = ['has-many' => [DrTeam::class, 'members', pivot(), 'relField' => 'members', 'localKey' => 'id']];
        $domain = DomainSchema::create()->field()->has_many(DrTeam::class, 'members')->pivot(pivot())->rel_field('members')->local_key('id');
        $structure = ModelDefinitionManager::instance()->source()->for_table($this->prefix . 'dr_user');
        $conf = \Engine\Atomic\App\Schema\Definition\StructurePolicyMerger::merge(
            $structure, DomainSchema::create()->define(['teams' => $domain]), DrUser::class
        );
        $this->assertSame($legacy, $conf['teams']);
    }

    // ─── behaviour through Cortex ─────────────────────────────────

    public function test_relations_round_trip(): void
    {
        $user = new DrUser();
        $user->name = 'ann';
        $user->uuid = 'u-1';
        $user->save();

        $tag = new DrTag();
        $tag->label = 't1';
        $tag->save();
        $user->tags = [$tag->_id];
        $user->save();

        $post = new DrPost();
        $post->title = 'hello';
        $post->author = $user;
        $post->author_uuid = $user;
        $post->save();

        $profile = new DrProfile();
        $profile->bio = 'bio';
        $profile->user = $user->_id;
        $profile->save();

        $team = new DrTeam();
        $team->name = 'red';
        $team->save();
        $user->teams = [$team->_id];
        $user->save();

        $post = (new DrPost())->findone(['id = ?', $post->_id]);
        $this->assertSame('ann', $post->author->name);
        $this->assertSame('ann', $post->author_uuid->name);
        $this->assertSame('u-1', $post->getRaw('author_uuid'));

        $user = (new DrUser())->findone(['id = ?', $user->_id]);
        $this->assertSame(['hello'], $user->posts->getAll('title'));
        $this->assertSame('bio', $user->profile->bio);
        $this->assertSame(['red'], $user->teams->getAll('name'));
        $this->assertSame(['t1'], $user->tags->getAll('label'));

        $team = (new DrTeam())->findone(['id = ?', $team->_id]);
        $this->assertSame(['ann'], $team->members->getAll('name'));
    }

    public function test_belongs_to_one_keeps_bigint_column_type(): void
    {
        $this->assertSame('INT8', $this->resolved(DrBigChild::class, 'owner')['type']);
    }

    public function test_mutual_domain_relations_do_not_repeat_live_schema_lookups(): void
    {
        new DrUser();
        new DrTeam();
        new DrTag();
        if (!is_string($this->db->log())) {
            $this->markTestSkipped('SQL log is disabled.');
        }
        $lookups = fn(): int => substr_count($this->db->log(), 'SHOW columns');
        // Mapper construction itself reads the schema; that cost is pre-existing.
        $before = $lookups();
        new DrTag();
        $per_instance = $lookups() - $before;

        $before = $lookups();
        for ($i = 0; $i < 3; $i++) {
            new DrUser();
            new DrTeam();
            DrUser::resolveConfiguration();
            DrTeam::resolveConfiguration();
        }
        $this->assertSame(6 * $per_instance, $lookups() - $before);
    }

    // ─── verification against the related model ───────────────────

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function badRelations(): array
    {
        return [
            'inverse declared on m:m side' => [BadInverseOnManyToMany::class,
                'field "teams" has-many relation expects ' . DrTeam::class . '.members to be belongs-to-one, but it is has-many; declare pivot() for a many-to-many'],
            'pivot declared on inverse side' => [BadPivotOnInverse::class,
                'field "posts" has-many relation with pivot() expects ' . DrPost::class . '.author to be has-many, but it is belongs-to-one'],
            'missing target field' => [BadMissingTargetField::class,
                'field "posts" has-many relation points at ' . DrPost::class . '.writer, which is not a configured field'],
            'target points at another class' => [BadBackClass::class,
                'expects ' . DrProfile::class . '.user to point back at ' . BadBackClass::class . ', but it points at ' . DrUser::class],
            'has-one target is not belongs-to-one' => [BadHasOneTarget::class,
                'field "profile" has-one relation expects ' . DrProfile::class . '.bio to be belongs-to-one, but it is not a relation'],
            'pivot table differs' => [BadPivotMismatch::class,
                'pivot table (computed) does not match ' . DrSquadNamedPivot::class . '.members pivot table "' . pivot() . '"'],
            'm:m back field differs' => [BadPivotBackField::class,
                'field "squads" has-many relation with pivot() expects ' . DrSquadOtherField::class . '.members to be has-many [' . BadPivotBackField::class . ', "squads"]'],
            'belongs-to-one target field' => [BadBelongsToField::class,
                'field "author_uuid" belongs-to-one relation targets ' . DrUser::class . '.handle, which is neither a configured field nor the primary key'],
            'belongs-to-many target field' => [BadBelongsToManyField::class,
                'field "tags" belongs-to-many relation targets ' . DrTag::class . '.slug'],
        ];
    }

    /** @dataProvider badRelations */
    public function test_contradicting_relation_sides_fail_on_instantiation(string $class, string $message): void
    {
        try {
            new $class();
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertStringContainsString('Model ' . $class, $e->getMessage());
        }
    }

    public function test_domain_class_cannot_redeclare_inherited_legacy_relation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field "author": ' . DomainRedeclaresRelation::class . ' redeclares the belongs-to-one relation inherited from a parent class');
        ModelDefinitionManager::instance()->collect(DomainRedeclaresRelation::class);
    }

    public function test_legacy_class_cannot_redeclare_inherited_domain_relation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(LegacyRedeclaresRelation::class . ' redeclares in fieldConf the belongs-to-one relation declared in a parent domain_schema()');
        ModelDefinitionManager::instance()->collect(LegacyRedeclaresRelation::class);
    }

    public function test_child_policy_keeps_inherited_domain_relation(): void
    {
        $conf = ModelDefinitionManager::instance()->collect(DomainPolicyOnInheritedRelation::class);
        $this->assertSame(DrUser::class, $conf['author']['belongs-to-one']);
        $this->assertSame(64, $conf['title']['max']);
        $this->assertSame([], DomainPolicyOnInheritedRelation::lint());
    }

    public function test_relation_models_lint_clean(): void
    {
        foreach ([DrUser::class, DrPost::class, DrProfile::class, DrTeam::class, DrPeer::class] as $class) {
            $this->assertSame([], $class::lint(), $class);
        }
    }
}
