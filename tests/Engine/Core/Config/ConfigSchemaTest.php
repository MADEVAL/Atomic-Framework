<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Config;

use Engine\Atomic\Core\Config\ConfigSchema;
use PHPUnit\Framework\TestCase;

final class ConfigSchemaTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigSchema::reset();
    }

    public function test_define_string_key(): void
    {
        ConfigSchema::string('APP_NAME')->default('Atomic');

        $this->assertTrue(ConfigSchema::has('APP_NAME'));
        $this->assertSame('Atomic', ConfigSchema::defaults()['APP_NAME']);
    }

    public function test_define_required_key(): void
    {
        ConfigSchema::string('APP_KEY')->required();

        $def = ConfigSchema::definitions()['APP_KEY'];
        $this->assertTrue($def->isRequired());
    }

    public function test_define_bool_key(): void
    {
        ConfigSchema::bool('DEBUG_MODE')->default(false);

        $this->assertSame(false, ConfigSchema::defaults()['DEBUG_MODE']);
    }

    public function test_define_int_key(): void
    {
        ConfigSchema::int('MAX_COUNT')->default(10)->min(1)->max(100);

        $this->assertSame(10, ConfigSchema::defaults()['MAX_COUNT']);
    }

    public function test_define_float_key(): void
    {
        ConfigSchema::float('RATIO')->default(1.5);

        $this->assertSame(1.5, ConfigSchema::defaults()['RATIO']);
    }

    public function test_define_csv_key(): void
    {
        ConfigSchema::csv('ALLOWED_HOSTS')->default('localhost,127.0.0.1');

        $this->assertSame('localhost,127.0.0.1', ConfigSchema::defaults()['ALLOWED_HOSTS']);
    }

    public function test_define_array_key(): void
    {
        ConfigSchema::array('DB_CONFIG')->default(['host' => 'localhost']);

        $this->assertSame(['host' => 'localhost'], ConfigSchema::defaults()['DB_CONFIG']);
    }

    public function test_define_enum_key(): void
    {
        ConfigSchema::enum('QUEUE_DRIVER', ConfigSchemaTest_Driver::class)
            ->default(ConfigSchemaTest_Driver::DATABASE);

        $this->assertSame(ConfigSchemaTest_Driver::DATABASE, ConfigSchema::defaults()['QUEUE_DRIVER']);
    }

    public function test_reset_clears_all_definitions(): void
    {
        ConfigSchema::string('SOME_KEY');
        $this->assertTrue(ConfigSchema::has('SOME_KEY'));

        ConfigSchema::reset();

        $this->assertFalse(ConfigSchema::has('SOME_KEY'));
    }

    public function test_required_without_has_no_default(): void
    {
        ConfigSchema::string('REQUIRED_KEY')->required();

        $this->assertArrayNotHasKey('REQUIRED_KEY', ConfigSchema::defaults());
    }

    public function test_nullable_key_has_null_default(): void
    {
        ConfigSchema::string('NULLABLE_KEY')->nullable();

        $this->assertNull(ConfigSchema::defaults()['NULLABLE_KEY']);
    }

    public function test_pipeline_fluent_syntax(): void
    {
        ConfigSchema::string('PIPELINE')
            ->default('default')
            ->required()
            ->minLength(3)
            ->maxLength(255)
            ->pattern('/^[a-z]+$/');

        $def = ConfigSchema::definitions()['PIPELINE'];
        $this->assertTrue($def->isRequired());
        $this->assertSame('default', ConfigSchema::defaults()['PIPELINE']);
    }

    public function test_has_returns_false_for_undefined(): void
    {
        $this->assertFalse(ConfigSchema::has('UNDEFINED_KEY'));
    }

    public function test_definitions_returns_all_registered(): void
    {
        ConfigSchema::string('A')->default('a');
        ConfigSchema::int('B')->default(1);

        $defs = ConfigSchema::definitions();
        $this->assertCount(2, $defs);
        $this->assertArrayHasKey('A', $defs);
        $this->assertArrayHasKey('B', $defs);
    }
}

enum ConfigSchemaTest_Driver: string
{
    case DATABASE = 'database';
    case REDIS = 'redis';
}
