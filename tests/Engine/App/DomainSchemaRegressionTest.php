<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Definition\FieldConfLinter;
use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\ModelDefinitionManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;

if (!defined('ATOMIC_START')) exit;

class VfDefaultsModel extends Model
{
    protected $table = 'vf_defaults';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define([]);
    }
}

class VfRequiredDefaultsModel extends VfDefaultsModel
{
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

class VfUnknownTypeModel extends Model
{
    protected $table = 'vf_unknown';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        return $schema->define(['name' => $schema->field()->required()]);
    }
}

final class DomainSchemaRegressionTest extends TestCase
{
    private \DB\SQL $db;
    private string $prefix;
    private array $tables = ['vf_defaults', 'vf_unknown'];

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

    // 1. NOT NULL columns with DDL defaults block save()
    public function test_save_relies_on_db_defaults(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_defaults` (id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL DEFAULT 'anon', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $m = new VfDefaultsModel();
        $conf = $m->getFieldConfiguration();
        $this->assertSame(true, $conf['name']['has_db_default'] ?? false);
        $this->assertArrayNotHasKey('default', $conf['created_at']);
        $saved = $m->save();
        $this->assertNotFalse($saved, 'save failed: ' . json_encode($m->get_last_err_data()));
        $row = $this->db->exec("SELECT * FROM `{$this->prefix}vf_defaults`")[0];
        $this->assertSame('anon', $row['name']);
        $this->assertNotNull($row['created_at']);
    }

    public function test_required_overrides_db_default(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_defaults` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL DEFAULT 'anon')");
        $m = new VfRequiredDefaultsModel();
        $this->assertSame(false, $m->save());
    }

    public function test_explicit_null_still_throws(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_defaults` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL DEFAULT 'anon')");
        $m = new VfDefaultsModel();
        $this->expectException(\Exception::class);
        $m->name = null;
    }

    // 2. unknown column type the model never mentions crashes the model
    public function test_unrelated_unknown_column_type(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_unknown` (id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL, token BIT(64) NULL, flag BIT(8) NULL)");
        $m = new VfUnknownTypeModel();
        $this->assertArrayHasKey('name', $m->getFieldConfiguration());
        $this->assertArrayNotHasKey('token', $m->getFieldConfiguration());
        $m->name = 'valid';
        $m->token = 49;
        $this->assertNotFalse($m->save());
    }

    public function test_unknown_rules_and_legacy_type_behavior(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_unknown` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, token BIT(64) NOT NULL)");
        foreach (['required', 'enum_values', 'regex', 'callback', 'unique'] as $rule) {
            VfUnknownRulesModel::$rule = $rule;
            ModelDefinitionManager::reset();
            $m = new VfUnknownRulesModel();
            $conf = $m->getFieldConfiguration()['token'];
            $this->assertSame('DOMAIN_UNTYPED', $conf['type']);
            $this->assertSame([true], \Engine\Atomic\Validator\Validator::validate_model($conf, '49', 'token', $m));
            $bad = $rule === 'required' ? null : '50';
            if ($rule !== 'unique') $this->assertSame(false, \Engine\Atomic\Validator\Validator::validate_model($conf, $bad, 'token', $m)[0]);
            $this->assertSame([false, \Engine\Atomic\Codes\Code::SERVER_ERROR], \Engine\Atomic\Validator\Validator::validate_model(['type'=>'unsupported'], 'x', 'token', $m));
            $this->assertSame([true], \Engine\Atomic\Validator\Validator::validate_model(['type'=>'INT4', 'default'=>1, 'required'=>true], null, 'token', $m));
            $this->assertSame([], VfUnknownRulesModel::lint());
        }
        VfUnknownRulesModel::$rule = 'unique';
        ModelDefinitionManager::reset();
        $m = new VfUnknownRulesModel();
        $m->name = 'valid';
        $m->token = 49;
        $this->assertNotFalse($m->save());
        $this->db->exec("UPDATE `{$this->prefix}vf_unknown` SET token = 49");
        $other = new VfUnknownRulesModel();
        $other->name = 'other';
        $other->token = 49;
        $this->assertSame(false, $other->save());
    }

    public function test_unknown_type_rejects_type_dependent_rules(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_unknown` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, token BIT(64) NOT NULL)");
        foreach (['str_min','str_max','mb_min','mb_max','num_min','num_max','json','serialized','email','url','uuid_v4','password_entropy'] as $rule) {
            VfUnknownRulesModel::$rule = $rule;
            ModelDefinitionManager::reset();
            try {
                new VfUnknownRulesModel();
                $this->fail('Expected LogicException for '.$rule);
            } catch (\LogicException $e) {
                foreach ([VfUnknownRulesModel::class, 'token', 'bit(64)', $rule] as $part) {
                    $this->assertStringContainsString($part, $e->getMessage());
                }
            }
        }
    }

    public function test_skipped_unknown_columns_log_once_even_after_reset_and_lint(): void
    {
        $this->db->exec("CREATE TABLE `{$this->prefix}vf_unknown` (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, token BIT(64) NULL, flag BIT(8) NULL)");
        $prop = new \ReflectionProperty(\Engine\Atomic\Core\Log::class, 'channels');
        $channels = $prop->getValue();
        $configs = new \ReflectionProperty(\Engine\Atomic\Core\Log::class, 'channel_configs');
        $default = (new \ReflectionProperty(\Engine\Atomic\Core\Log::class, 'default_channel'))->getValue();
        $original_configs = $configs->getValue();
        $config = $original_configs[$default] ?? ['path'=>'schema-test.log'];
        $configs->setValue(null, $original_configs + [$default=>$config]);
        $logger = new class extends \Log {
            public array $messages = [];
            public function __construct() {}
            public function write($text, $format = 'r') { $this->messages[] = $text; }
        };
        $captured = $channels;
        $captured[$default] = ['logger'=>$logger, 'level'=>PHP_INT_MAX, 'path'=>\Engine\Atomic\Core\Log::resolve_dated_path($config['path'])];
        $prop->setValue(null, $captured);
        try {
            new VfUnknownLogModel();
            ModelDefinitionManager::reset();
            new VfUnknownLogModel();
            $problems = VfUnknownLogModel::lint();
            $this->assertSame(2, count($problems));
            $this->assertSame($problems, ModelDefinitionManager::instance()->lint(VfUnknownLogModel::class));
            $this->assertSame(2, count($logger->messages));
            foreach (['token'=>'bit(64)', 'flag'=>'bit(8)'] as $column=>$raw) {
                $matching = array_values(array_filter($logger->messages, static fn($m)=>str_contains($m, 'column "'.$column.'"')));
                $this->assertSame(1, count($matching));
                foreach (['[WARNING]', VfUnknownLogModel::class, $this->prefix.'vf_unknown', $raw, 'pre-save validation is skipped'] as $part) {
                    $this->assertStringContainsString($part, $matching[0]);
                }
                $this->assertStringContainsString($column, implode(' ', $problems));
            }
        } finally {
            $prop->setValue(null, $channels);
            $configs->setValue(null, $original_configs);
        }
    }
}

class VfUnknownRulesModel extends VfUnknownTypeModel
{
    public static string $rule = 'required';
    protected static function domain_schema(DomainSchema $schema): DomainDefinition
    {
        $args = match (self::$rule) {
            'enum_values' => [['49']],
            'regex' => ['/^49$/D'],
            'callback' => [static fn($v) => $v === '49'],
            'str_min','str_max','mb_min','mb_max','num_min','num_max' => [2],
            default => [],
        };
        return $schema->define(['token'=>$schema->field()->{self::$rule}(...$args)]);
    }
}

class VfUnknownLogModel extends VfUnknownTypeModel {}
