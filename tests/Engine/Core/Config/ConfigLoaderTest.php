<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Config;

use Engine\Atomic\Core\Config\V2\Config;
use Engine\Atomic\Core\Config\V2\ConfigLoader;
use Engine\Atomic\Core\Config\ConfigSchema;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderV2Test extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        ConfigSchema::reset();
        $this->tmpDir = sys_get_temp_dir() . '/atomic_config_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        // Register test schema
        ConfigSchema::string('APP_NAME')->default('Atomic');
        ConfigSchema::bool('DEBUG_MODE')->default(false);
        ConfigSchema::string('APP_KEY')->required();
        ConfigSchema::int('MAX_COUNT')->default(10);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        ConfigSchema::reset();
    }

    public function test_from_defaults_loads_schema_defaults(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'required-for-validation-32-chars'])
            ->load();

        $this->assertSame('Atomic', $config->string('APP_NAME'));
        $this->assertSame(false, $config->bool('DEBUG_MODE'));
        $this->assertSame(10, $config->int('MAX_COUNT'));
    }

    public function test_from_env_file_overrides_defaults(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_NAME=MyApp\nDEBUG_MODE=true\nMAX_COUNT=42\nAPP_KEY=env-key-32-chars-minimum-needed");

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromEnvFile($this->tmpDir . '/.env')
            ->load();

        $this->assertSame('MyApp', $config->string('APP_NAME'));
        $this->assertSame(true, $config->bool('DEBUG_MODE'));
        $this->assertSame(42, $config->int('MAX_COUNT'));
    }

    public function test_from_array_overrides_previous(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_NAME' => 'FromArray', 'APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertSame('FromArray', $config->string('APP_NAME'));
        $this->assertSame('secret-key-32-chars-minimum!!', $config->string('APP_KEY'));
    }

    public function test_last_source_wins(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_NAME' => 'First', 'APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->fromArray(['APP_NAME' => 'Second'])
            ->load();

        $this->assertSame('Second', $config->string('APP_NAME'));
    }

    public function test_parse_env_handles_comments_and_empty_lines(): void
    {
        file_put_contents($this->tmpDir . '/.env', "# Comment\nAPP_NAME=Clean\n\n# Another comment\nMAX_COUNT=99\nAPP_KEY=comment-key-32-chars-length");

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromEnvFile($this->tmpDir . '/.env')
            ->load();

        $this->assertSame('Clean', $config->string('APP_NAME'));
        $this->assertSame(99, $config->int('MAX_COUNT'));
    }

    public function test_parse_env_handles_quotes(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_NAME=\"Quoted Name\"\nAPP_KEY=quoted-key-32-chars-minimum!");

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromEnvFile($this->tmpDir . '/.env')
            ->load();

        $this->assertSame('Quoted Name', $config->string('APP_NAME'));
    }

    public function test_required_key_with_no_value_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        ConfigLoader::create()
            ->fromDefaults()
            ->load();
    }

    public function test_has_returns_correctly(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertTrue($config->has('APP_NAME'));
        $this->assertFalse($config->has('UNDEFINED'));
    }

    public function test_all_returns_full_array(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $all = $config->all();
        $this->assertArrayHasKey('APP_NAME', $all);
        $this->assertArrayHasKey('DEBUG_MODE', $all);
        $this->assertArrayHasKey('APP_KEY', $all);
    }

    public function test_get_with_default_for_missing_key(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertSame('fallback', $config->string('UNDEFINED', 'fallback'));
        $this->assertSame(42, $config->int('UNDEFINED', 42));
    }

    public function test_apply_to_hive_populates_f3(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $base = \Base::instance();
        $base->clear('APP_NAME');
        $base->clear('DEBUG_MODE');

        $config->applyToHive($base);

        $this->assertSame('Atomic', $base->get('APP_NAME'));
        $this->assertSame(false, $base->get('DEBUG_MODE'));
    }

    public function test_csv_value_is_split(): void
    {
        ConfigSchema::csv('ALLOWED_HOSTS')->default('localhost,127.0.0.1');

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertSame(['localhost', '127.0.0.1'], $config->csv('ALLOWED_HOSTS'));
    }

    public function test_float_value(): void
    {
        ConfigSchema::float('RATIO')->default(1.5);

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertSame(1.5, $config->float('RATIO'));
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
