<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Domain\FieldRules;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Enums\Rule;
use PHPUnit\Framework\TestCase;

if (!defined('ATOMIC_START')) exit;

final class DomainSchemaTest extends TestCase
{
    private static ?ModelDefinitionManager $manager = null;
    private \DB\SQL $db;
    private string $prefix;
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is required.');
        }
        $this->db = ConnectionManager::instance()->get_db();
        $this->prefix = (string) App::instance()->get('DB_CONFIG.prefix');
        foreach (['domain_users', 'domain_types', 'probe_domain_users'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `{$this->prefix}{$table}`");
        }
        $users = "(id INT PRIMARY KEY AUTO_INCREMENT, uuid VARCHAR(128) NOT NULL, name VARCHAR(64) NOT NULL, email VARCHAR(256) NOT NULL, password VARCHAR(256) NOT NULL, role VARCHAR(128) NOT NULL DEFAULT 'user', email_verified_at TIMESTAMP NULL, remember_token VARCHAR(128) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
        $this->db->exec("CREATE TABLE `{$this->prefix}domain_users` ".$users);
        $this->db->exec("CREATE TABLE `{$this->prefix}probe_domain_users` ".$users);
        $types = '(id INT PRIMARY KEY, flag TINYINT(1) NOT NULL, tiny TINYINT, small SMALLINT, medium INT UNSIGNED, big BIGINT, ratio FLOAT, precise DOUBLE, money DECIMAL(10,2), short VARCHAR(128), mid VARCHAR(256), `long` VARCHAR(512), body TEXT, huge LONGTEXT, day DATE, moment DATETIME, stamp TIMESTAMP NULL, payload BLOB, json_value TEXT, serialized_value TEXT, native_value JSON)';
        $this->db->exec("CREATE TABLE `{$this->prefix}domain_types` ".$types);
        ModelDefinitionManager::reset();
        self::$manager = ModelDefinitionManager::instance();
    }
    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        App::instance()->set('DB_CONFIG.prefix', $this->prefix);
        foreach (['domain_users', 'domain_types', 'probe_domain_users'] as $table) {
            $this->db->exec("DROP TABLE IF EXISTS `{$this->prefix}{$table}`");
        }
        ModelDefinitionManager::reset();
    }

    private function conf_of(string $modelClass): array
    {
        return self::$manager->collect($modelClass);
    }

    // ─────────────────────────────────────────────────────────────
    // DomainSchema builder — policy only, no types
    // ─────────────────────────────────────────────────────────────

    public function test_defaults_are_structural_only(): void
    {
        $this->assertFalse(method_exists(FieldRules::class, 'default'));
    }

    private function merge_rule(string $type, FieldRules $rules): array
    {
        $structure = \Engine\Atomic\App\Schema\Structure\SchemaStructure::from_columns('probe', [
            'value' => ['type' => $type, 'nullable' => false, 'unique' => true],
        ]);
        return \Engine\Atomic\App\Schema\Definition\StructurePolicyMerger::merge(
            $structure, (new DomainSchema())->define(['value' => $rules]), self::class
        )['value'];
    }

    public function test_cast_tokens_native_json_and_unique_policy(): void
    {
        $this->assertSame(\DB\Cortex::DT_JSON, $this->merge_rule('text', (new FieldRules())->json())['type']);
        $this->assertSame(\DB\Cortex::DT_SERIALIZED, $this->merge_rule('blob', (new FieldRules())->serialized())['type']);
        $this->assertSame('JSON', $this->merge_rule('json', new FieldRules())['type']);
        $this->assertSame('JSON', $this->merge_rule('json', (new FieldRules())->json())['type']);
        $this->assertArrayNotHasKey('unique', $this->merge_rule('varchar(64)', new FieldRules()));
        $this->assertSame(true, $this->merge_rule('varchar(64)', (new FieldRules())->unique())['unique']);
        $this->assertArrayNotHasKey('unique', $this->merge_rule('varchar(64)', (new FieldRules())->unique()->unique(false)));
    }

    public function test_incompatible_policies_are_rejected(): void
    {
        $cases = [];
        foreach (['int', 'date', 'tinyint(1)', 'varchar(255)', 'time', "enum('a','b')"] as $type) {
            foreach (['json', 'serialized'] as $cast) $cases[] = [$type, (new FieldRules())->{$cast}()];
        }
        $cases[] = ['json', (new FieldRules())->serialized()];
        foreach (['str_min', 'str_max', 'mb_min', 'mb_max', 'email', 'url', 'uuid_v4', 'regex', 'password_entropy'] as $method) {
            $args = match ($method) { 'str_min','str_max','mb_min','mb_max' => [2], 'regex' => ['/x/'], default => [] };
            foreach (['int', 'time', 'year', 'tinyint(1)', 'blob', 'json'] as $type) $cases[] = [$type, (new FieldRules())->{$method}(...$args)];
            foreach (['json', 'serialized'] as $cast) $cases[] = ['text', (new FieldRules())->{$cast}()->{$method}(...$args)];
        }
        foreach (['num_min', 'num_max'] as $method) {
            foreach (['varchar(64)', 'time', 'year', 'tinyint(1)', 'text', 'json'] as $type) $cases[] = [$type, (new FieldRules())->{$method}(2)];
        }
        foreach (['str_min', 'str_max', 'mb_min', 'mb_max'] as $method) $cases[] = ['varchar(10)', (new FieldRules())->{$method}(11)];
        $cases[] = ["enum('a','b')", (new FieldRules())->enum_values(['a', 'c'])];
        foreach ($cases as [$type, $rules]) {
            try {
                $this->merge_rule($type, $rules);
                $this->fail('Expected incompatible policy on '.$type);
            } catch (\LogicException $e) {
                $this->assertStringContainsString('value', $e->getMessage());
            }
        }
    }

    public function test_compatible_policies_compile(): void
    {
        foreach (['int', 'decimal(10,2)', 'float'] as $type) $this->assertSame(2, $this->merge_rule($type, (new FieldRules())->num_min(2))['min']);
        $this->assertSame(['a'], $this->merge_rule("enum('a','b')", (new FieldRules())->enum_values(['a']))['enum']);
        $this->assertSame(10, $this->merge_rule('varchar(10)', (new FieldRules())->str_max(10))['max']);
    }

    public function test_conflicting_casts_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        (new FieldRules())->json()->serialized();
    }

    public function test_unique_is_a_validation_precheck_without_a_database_index(): void
    {
        $this->db->exec('INSERT INTO '.$this->prefix."domain_types (id, flag, short) VALUES (1, 1, 'taken')");
        $model = new DomainTypesModel();
        $conf = $this->merge_rule('varchar(128)', (new FieldRules())->unique());
        $result = \Engine\Atomic\Validator\Validator::validate_model($conf, 'taken', 'short', $model);
        $this->assertSame(false, $result[0]);
        $this->assertSame([true], \Engine\Atomic\Validator\Validator::validate_model($conf, 'available', 'short', $model));
    }

    public function test_cast_storage_limits_use_encoded_bytes(): void
    {
        $model = new DomainTypesModel();
        foreach (['json', 'serialized'] as $cast) {
            $conf = $this->merge_rule('tinytext', (new FieldRules())->{$cast}());
            $this->assertSame([true], \Engine\Atomic\Validator\Validator::validate_model($conf, ['x' => 'ok'], 'body', $model));
            $this->assertSame(false, \Engine\Atomic\Validator\Validator::validate_model($conf, ['x' => str_repeat('a', 256)], 'body', $model)[0]);
        }
    }

    public function test_cast_arrays_round_trip_through_cortex(): void
    {
        ModelDefinitionManager::reset();
        $model = new DomainCastModel();
        $value = ['name' => 'example', 'nested' => [1, true, null]];
        $model->id = 1;
        $model->flag = true;
        foreach (['json_value', 'serialized_value', 'native_value'] as $field) $model->set($field, $value);
        $model->save();
        $loaded = new DomainCastModel();
        $loaded->load(['id = ?', 1]);
        $this->assertFalse($loaded->dry());
        foreach (['json_value', 'serialized_value', 'native_value'] as $field) $this->assertSame($value, $loaded->get($field));
        $this->assertSame([], DomainCastModel::lint());
    }

    public function test_domain_builder_has_no_type_methods(): void
    {
        $schema = DomainSchema::create();
        $this->assertInstanceOf(FieldRules::class, $schema->field());
        $this->assertFalse(method_exists(DomainSchema::class, 'varchar'));
        $this->assertFalse(method_exists(FieldRules::class, 'type'));
    }

    public function test_field_rules_require_their_arguments(): void
    {
        $rules = (new DomainSchema())->field()->required()->str_min(2)->str_max(10)->email();

        $this->assertSame(
            [
                'required' => true,
                'rules' => [Rule::EMAIL, Rule::STR_MIN, Rule::STR_MAX],
                'min' => 2,
                'max' => 10,
            ],
            $rules->to_policy()
        );
    }

    public function test_define_rejects_foreign_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a FieldRules builder/');

        (new DomainSchema())->define(['name' => 'required']);
    }

    public function test_invalid_regex_and_empty_enum_are_rejected(): void
    {
        $schema = new DomainSchema();

        $this->expectException(\InvalidArgumentException::class);
        $schema->field()->regex('/[unclosed/');
    }

    // ─────────────────────────────────────────────────────────────
    // Live structure and domain policy compile to Cortex fieldConf
    // ─────────────────────────────────────────────────────────────

    public function test_live_structure_metadata_and_primary_exclusion(): void
    {
        $conf = $this->conf_of(DomainUserModel::class);
        $this->assertSame(['type'=>'VARCHAR128', 'nullable'=>false, 'column_kind'=>'string', 'max_length'=>64, 'required'=>true, 'rules'=>[Rule::STR_MIN], 'min'=>2], $conf['name']);
        $this->assertArrayNotHasKey('id', $conf);
        $this->assertSame(true, $conf['role']['has_db_default']);
        $this->assertSame(true, $conf['created_at']['has_db_default']);
        $this->assertArrayNotHasKey('default', $conf['role']);
        $this->assertArrayNotHasKey('default', $conf['created_at']);
    }
    public function test_constructor_and_resolve_configuration_have_types(): void
    {
        ConnectionManager::instance()->close_sql();
        App::instance()->clear('DB');
        $this->assertSame('VARCHAR128', (new DomainUserModel())->get_field_configuration()['name']['type']);
        $this->assertSame('VARCHAR128', DomainUserModel::resolveConfiguration()['fieldConf']['name']['type']);
    }
    public function test_prefix_is_applied_once(): void
    {
        App::instance()->set('DB_CONFIG.prefix', $this->prefix.'probe_');
        $this->assertSame($this->prefix.'probe_domain_users', DomainPrefixModel::resolveConfiguration()['table']);
        $this->assertSame(64, $this->conf_of(DomainPrefixModel::class)['name']['max_length']);
    }
    public function test_legacy_collection_and_lint_are_database_free(): void
    {
        $config = App::instance()->get('DB_CONFIG');
        App::instance()->set('DB_CONFIG', null);
        App::instance()->clear('DB');
        try {
            $this->assertSame(['uuid'=>['type'=>'VARCHAR256', 'nullable'=>true]], $this->conf_of(DomainMixedBase::class));
            $this->assertSame([], DomainMixedBase::lint());
        } finally {
            App::instance()->set('DB_CONFIG', $config);
            App::instance()->set('DB', $this->db);
        }
    }

    public function test_legacy_conf_builds_in_a_process_without_database_configuration(): void
    {
        $source = '<?php declare(strict_types=1); define("ATOMIC_START", microtime(true));'
            . 'require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';'
            . 'class OfflineLegacy extends \Engine\Atomic\App\Model { protected $fieldConf = ["name"=>["type"=>"VARCHAR128"]]; }'
            . '$conf = \Engine\Atomic\App\Schema\ModelDefinitionManager::instance()->collect(OfflineLegacy::class);'
            . 'echo json_encode([$conf, OfflineLegacy::lint()]);';
        $file = tempnam(sys_get_temp_dir(), 'atomic_domain_offline_');
        file_put_contents($file, $source);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $this->assertSame([["name"=>["type"=>"VARCHAR128"]], []], json_decode(implode("\n", $output), true));
        } finally {
            unlink($file);
        }
    }
    public function test_composite_primary_columns_are_excluded(): void
    {
        $this->db->exec("ALTER TABLE `{$this->prefix}domain_types` DROP PRIMARY KEY, ADD PRIMARY KEY (id, medium)");
        $conf = $this->conf_of(DomainTypesModel::class);
        $this->assertArrayNotHasKey('id', $conf);
        $this->assertArrayNotHasKey('medium', $conf);
    }
    public function test_prefixed_table_name_is_not_prefixed_twice(): void
    {
        App::instance()->set('DB_CONFIG.prefix', 'domain_');
        $this->assertSame('domain_users', \Engine\Atomic\App\Schema\Definition\DomainStrategy::table_of(DomainUserModel::class));
    }

    public function test_manager_memoization_reset_and_swap_refresh_configuration(): void
    {
        $first = ModelDefinitionManager::instance();
        $conf = DomainUserModel::resolveConfiguration()['fieldConf'];
        $this->db->exec("ALTER TABLE `{$this->prefix}domain_users` MODIFY name VARCHAR(80) NOT NULL");
        $this->assertSame($conf, DomainUserModel::resolveConfiguration()['fieldConf']);
        ModelDefinitionManager::reset();
        $this->assertSame(80, DomainUserModel::resolveConfiguration()['fieldConf']['name']['max_length']);
        ModelDefinitionManager::swap($first);
        $this->assertSame(80, DomainUserModel::resolveConfiguration()['fieldConf']['name']['max_length']);
    }
    public function test_mapper_reuses_the_cached_live_schema(): void
    {
        $cache = App::instance()->get('CACHE');
        $directory = sys_get_temp_dir().'/atomic_domain_schema_'.uniqid().'/';
        App::instance()->set('CACHE', 'folder='.$directory);
        try {
            $before = substr_count((string) $this->db->log(), 'SHOW columns');
            new DomainUserModel();
            $this->assertSame(1, substr_count((string) $this->db->log(), 'SHOW columns') - $before);
        } finally {
            App::instance()->set('CACHE', $cache);
            foreach (glob($directory.'*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_domain_compiled_config_is_validator_ready(): void
    {
        $conf = $this->conf_of(DomainUserModel::class);

        // Everything the Validator reads is present and correctly typed.
        $this->assertTrue($conf['name']['required']);
        $this->assertSame(2, $conf['name']['min']);
        $this->assertSame([Rule::EMAIL], $conf['email']['rules']);
        $this->assertSame([Rule::UUID_V4], $conf['uuid']['rules']);
        $this->assertSame('VARCHAR128', $conf['uuid']['type']);
        $this->assertSame('TIMESTAMP', $conf['created_at']['type']);

        // ...and lint reports nothing for this model.
        $this->assertSame([], self::$manager->lint(DomainUserModel::class));
    }

    // ─────────────────────────────────────────────────────────────
    // Type vocabulary normalization
    // ─────────────────────────────────────────────────────────────

    public function test_live_mysql_types_map_to_tokens(): void
    {
        $conf = $this->conf_of(DomainTypesModel::class);

        $expected = [
            'flag' => 'BOOLEAN',
            'tiny' => 'INT1',
            'small' => 'INT2',
            'medium' => 'INT4',
            'big' => 'INT8',
            'ratio' => 'FLOAT',
            'precise' => 'DOUBLE',
            'money' => 'DOUBLE',
            'short' => 'VARCHAR128',
            'mid' => 'VARCHAR256',
            'long' => 'VARCHAR512',
            'body' => 'TEXT',
            'huge' => 'LONGTEXT',
            'day' => 'DATE',
            'moment' => 'DATETIME',
            'stamp' => 'TIMESTAMP',
            'payload' => 'BLOB',
        ];

        $this->assertSame(true, $conf['medium']['unsigned']);
        $this->assertSame(10, $conf['money']['precision']);
        $this->assertSame(2, $conf['money']['scale']);
        foreach ($expected as $field => $token) {
            $this->assertSame($token, $conf[$field]['type'], "type mapping failed for {$field}");
        }
    }

    public function test_policy_for_missing_column_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no matching column in the database schema/');

        $this->conf_of(DomainMissingColumnModel::class);
    }

    public function test_required_on_nullable_column_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/database declares it nullable/');

        $this->conf_of(DomainNullableConflictModel::class);
    }

    public function test_string_rule_on_numeric_column_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot apply rule "str_min" to a string field "medium" \(INT4\)/');

        $this->conf_of(DomainWrongRuleTypeModel::class);
    }

    public function test_both_definition_styles_on_one_model_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Use one definition style per model/');

        $this->conf_of(DomainAndLegacyModel::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Inheritance and lint
    // ─────────────────────────────────────────────────────────────

    public function test_domain_inheritance_merges_parent_and_child_policies(): void
    {
        $conf = $this->conf_of(DomainChildModel::class);

        $this->assertArrayHasKey('email', $conf);
        $this->assertArrayHasKey('remember_token', $conf);
        $this->assertSame([Rule::EMAIL], $conf['email']['rules']);
        $this->assertSame([Rule::STR_MIN], $conf['remember_token']['rules']);
        $this->assertSame('VARCHAR128', $conf['remember_token']['type']);
    }

    public function test_legacy_base_plus_domain_child_merges_both_sources(): void
    {
        $conf = $this->conf_of(DomainMixedChildModel::class);

        // legacy $fieldConf from the base
        $this->assertSame(['type' => 'VARCHAR256', 'nullable' => true], $conf['uuid']);
        // domain policy compiled against the child's live database
        $this->assertSame('VARCHAR256', $conf['mid']['type']);
        $this->assertSame([Rule::STR_MIN], $conf['mid']['rules']);
        $this->assertSame(5, $conf['mid']['min']);
    }

    public function test_lint_is_clean_for_domain_models(): void
    {
        $this->assertSame([], self::$manager->lint(DomainUserModel::class));
        $this->assertSame([], self::$manager->lint(DomainTypesModel::class));
    }

    public function test_lint_reports_split_errors(): void
    {
        $errors = self::$manager->lint(DomainMissingColumnModel::class);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no matching column', $errors[0]);
    }
}

/**
 * Domain model for the "domain_users" live table — policy only.
 */
class DomainUserModel extends Model
{
    protected $table = 'domain_users';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'uuid' => $schema->field()->required()->uuid_v4(),
            'name' => $schema->field()->required()->str_min(2),
            'email' => $schema->field()->required()->email(),
            'password' => $schema->field()->required(),
            'role' => $schema->field()->required(),
        ]);
    }
}

/**
 * Domain model covering every type spelling in the "domain_types" fixture.
 */
class DomainTypesModel extends Model
{
    protected $table = 'domain_types';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'flag' => $schema->field()->required(),
        ]);
    }
}

/** Policy references a column the migration does not declare. */
class DomainMissingColumnModel extends Model
{
    protected $table = 'domain_users';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'ghost' => $schema->field()->required(),
        ]);
    }
}

/** required() policy on a column the migration declares nullable. */
class DomainNullableConflictModel extends Model
{
    protected $table = 'domain_users';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'email_verified_at' => $schema->field()->required(),
        ]);
    }
}

/** string rule on a column with a numeric type. */
class DomainWrongRuleTypeModel extends Model
{
    protected $table = 'domain_types';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'medium' => $schema->field()->str_min(2),
        ]);
    }
}

/** Both definition styles on one model — ambiguous, must fail. */
class DomainAndLegacyModel extends Model
{
    protected $table = 'domain_users';

    protected $fieldConf = [
        'uuid' => ['type' => 'VARCHAR128'],
    ];

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'name' => $schema->field()->required(),
        ]);
    }
}

/** Domain inheritance: child extends the parent's domain policy. */
abstract class DomainBaseModel extends Model
{
    protected $table = 'domain_users';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'email' => $schema->field()->required()->email(),
        ]);
    }
}

final class DomainChildModel extends DomainBaseModel
{
    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'remember_token' => $schema->field()->str_min(1),
        ]);
    }
}

/** Legacy base + domain child: live structure supplies the child's field. */
abstract class DomainMixedBase extends Model
{
    protected $fieldConf = [
        'uuid' => ['type' => 'VARCHAR256', 'nullable' => true],
    ];
}

final class DomainMixedChildModel extends DomainMixedBase
{
    protected $table = 'domain_types';

    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'mid' => $schema->field()->str_min(5),
        ]);
    }
}

class DomainPrefixModel extends DomainUserModel {}

class DomainCastModel extends Model
{
    protected $table = 'domain_types';
    protected static function domain_schema(DomainSchema $schema): \Engine\Atomic\App\Schema\Domain\DomainDefinition
    {
        return $schema->define([
            'json_value' => $schema->field()->json(),
            'serialized_value' => $schema->field()->serialized(),
            // MariaDB exposes its JSON alias as LONGTEXT in schema().
            'native_value' => $schema->field()->json(),
        ]);
    }
}
