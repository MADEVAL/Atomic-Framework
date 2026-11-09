<?php
declare(strict_types=1);
namespace Tests\Integration\Database;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Migrations\MigrationContext;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Enums\Rule;
use Engine\Atomic\Validator\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;
use Tests\Support\StreamCapture;

final class ModelMigrationCompatibilityTest extends TestCase
{
    private string $directory;
    private string $prefix;
    private \DB\SQL $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql')) $this->markTestSkipped('pdo_mysql required');
        $this->db = ConnectionManager::instance()->get_db();
        $this->prefix = (string) App::instance()->get('DB_CONFIG.prefix');
        $this->directory = TempPath::make_dir('model_matrix_');
        ModelDefinitionManager::reset();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach (['', 'probe_'] as $prefix) {
            foreach (['matrix_records', 'matrix_owners', 'matrix_children'] as $table) $this->db->exec("DROP TABLE IF EXISTS `{$prefix}{$table}`");
        }
        App::instance()->set('DB_CONFIG.prefix', $this->prefix);
        ModelDefinitionManager::reset();
        TempPath::remove($this->directory);
    }

    private function migrate(string $style, string $sql): void
    {
        $body = '\\Engine\\Atomic\\Core\\ConnectionManager::instance()->get_db()->exec('.var_export($sql, true).');';
        $source = $style === 'array'
            ? "return ['up' => static function (): void { $body }, 'down' => static function (): void {}];"
            : "return new class extends \\Engine\\Atomic\\Core\\Migrations\\Migration { public function up(): void { $body } public function down(): void {} };";
        $path = $this->directory.uniqid('migration_').'.php';
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n".$source);
        $output = new Output(StreamCapture::memory('w+b'), StreamCapture::memory('w+b'));
        (new MigrationExecutor(new MigrationContext(App::instance(), ConnectionManager::instance(), $output)))->up([
            'source' => 'app', 'migration' => basename($path, '.php'), 'path' => $path, 'checksum' => hash_file('sha256', $path),
        ]);
    }

    private function create(string $style, string $prefix = ''): void
    {
        App::instance()->set('DB_CONFIG.prefix', $prefix);
        $this->migrate($style, "CREATE TABLE `{$prefix}matrix_records` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NOT NULL)");
        ModelDefinitionManager::reset();
    }

    public static function matrix(): array
    {
        $cases = [];
        foreach (['array', 'class'] as $migration) foreach ([MatrixLegacy::class, MatrixDomain::class] as $model) foreach (['', 'probe_'] as $prefix) $cases[$migration.' '.$model.' '.$prefix] = [$migration, $model, $prefix];
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function test_model_and_migration_styles_round_trip(string $migration, string $class, string $prefix): void
    {
        $this->create($migration, $prefix);
        $model = new $class();
        $model->name = 'valid';
        $model->save();
        $loaded = new $class();
        $loaded->load(['id = ?', $model->id]);
        $this->assertFalse($loaded->dry());
        $this->assertSame('valid', $loaded->name);
        $loaded->name = 'x';
        $loaded->save();
        $this->assertSame('500', $loaded->get_last_err_code());
    }

    public function test_split_migrations_and_validation_parity(): void
    {
        foreach ([['array', 'class'], ['class', 'array']] as [$create, $alter]) {
            $this->create($create);
            $legacy = new MatrixLegacy();
            $before = $legacy->collect_field_conf();
            $this->migrate($alter, 'ALTER TABLE matrix_records ADD score SMALLINT NULL');
            ModelDefinitionManager::reset();
            $domain = new MatrixDomain();
            $this->assertSame('INT2', $domain->collect_field_conf()['score']['type']);
            $domain->name = 'split';
            $domain->score = 123;
            $domain->save();
            $loaded = new MatrixDomain();
            $loaded->load(['id = ?', $domain->id]);
            $this->assertSame(123, $loaded->score);
            $this->assertSame($before, (new MatrixLegacy())->collect_field_conf());
            foreach ([null, '', 'x', 'valid', str_repeat('x', 129)] as $input) {
                $this->assertSame(
                    Validator::validate_model($before['name'], $input, 'name', $legacy),
                    Validator::validate_model($domain->collect_field_conf()['name'], $input, 'name', $domain)
                );
            }
            $this->db->exec('DROP TABLE matrix_records');
        }
    }

    public function test_mixed_inheritance_merges_recursively_child_first(): void
    {
        $this->create('class');
        foreach ([MatrixLegacyDomain::class, MatrixDomainLegacy::class] as $class) {
            $model = new $class();
            $conf = $model->collect_field_conf()['name'];
            $this->assertSame(3, $conf['min']);
            $this->assertSame(true, $conf['required']);
            $this->assertSame([Rule::STR_MIN], $conf['rules']);
            $this->assertSame('VARCHAR128', $conf['type']);
            $model->name = 'mixed';
            $model->save();
            $loaded = new $class();
            $loaded->load(['id = ?', $model->id]);
            $this->assertSame('mixed', $loaded->name);
        }
        $this->expectException(\LogicException::class);
        new MatrixAmbiguous();
    }

    public function test_relations_cross_domain_and_legacy_in_both_directions(): void
    {
        $this->create('class');
        $this->migrate('array', 'ALTER TABLE matrix_records ADD owner INT NULL');
        ModelDefinitionManager::reset();
        foreach ([[MatrixLegacyOwner::class, MatrixDomainChild::class], [MatrixDomainOwnerPolicy::class, MatrixLegacyChild::class]] as [$ownerClass, $childClass]) {
            $owner = new $ownerClass();
            $owner->name = 'owner';
            $owner->save();
            $child = new $childClass();
            $child->name = 'child';
            $child->owner = $owner;
            $child->save();
            $loaded = new $childClass();
            $loaded->load(['id = ?', $child->id]);
            $this->assertSame('owner', $loaded->owner->name);
            $parent = new $ownerClass();
            $parent->load(['id = ?', $owner->id]);
            $this->assertSame(1, count($parent->children));
            $this->assertSame('child', $parent->children[0]->name);
        }
    }

    public function test_domain_configuration_constructs_once_per_class_until_reset_or_swap(): void
    {
        $this->create('array');
        MatrixDomain::$constructions = 0;
        $first = MatrixDomain::resolveConfiguration();
        $this->assertSame($first, MatrixDomain::resolveConfiguration());
        $this->assertSame(1, MatrixDomain::$constructions);
        ModelDefinitionManager::reset();
        MatrixDomain::resolveConfiguration();
        $this->assertSame(2, MatrixDomain::$constructions);
        ModelDefinitionManager::swap(ModelDefinitionManager::with(new \Engine\Atomic\App\Schema\Structure\LiveSchemaSource()));
        MatrixDomain::resolveConfiguration();
        MatrixDomain::resolveConfiguration();
        $this->assertSame(3, MatrixDomain::$constructions);
    }
}

class MatrixLegacy extends Model
{
    protected $table = 'matrix_records';
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128', 'required' => true, 'rules' => [Rule::STR_MIN], 'min' => 2]];
}
class MatrixDomain extends Model
{
    public static int $constructions = 0;
    protected $table = 'matrix_records';
    public function __construct() { self::$constructions++; parent::__construct(); }
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()->str_min(2)]);
    }
}

class MatrixLegacyDomain extends MatrixLegacy
{
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->str_min(3)]);
    }
}
class MatrixDomainLegacy extends MatrixDomain
{
    protected $fieldConf = ['name' => ['min' => 3]];
}
class MatrixAmbiguous extends MatrixLegacyDomain
{
    protected $fieldConf = ['name' => ['type' => 'VARCHAR128']];
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([]);
    }
}

class MatrixLegacyOwner extends MatrixLegacy
{
    protected $fieldConf = ['children' => ['has-many' => [MatrixDomainChild::class, 'owner']]];
}
class MatrixDomainOwner extends MatrixLegacy
{
    protected $fieldConf = ['children' => ['has-many' => [MatrixLegacyChild::class, 'owner']]];
}
class MatrixDomainOwnerPolicy extends MatrixDomainOwner
{
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([]);
    }
}
class MatrixChildRelations extends MatrixLegacy
{
    protected $fieldConf = ['owner' => ['belongs-to-one' => MatrixLegacyOwner::class]];
}
class MatrixDomainChild extends MatrixChildRelations
{
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([]);
    }
}
class MatrixLegacyChild extends MatrixLegacy
{
    protected $fieldConf = ['owner' => ['belongs-to-one' => MatrixDomainOwnerPolicy::class]];
}


