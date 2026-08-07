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
        $this->tmpDir = sys_get_temp_dir() . '/atomic_config_v2_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

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

    public function test_parse_env_handles_comments(): void
    {
        file_put_contents($this->tmpDir . '/.env', "# Comment\nAPP_NAME=Clean\nMAX_COUNT=99\nAPP_KEY=comment-key-32-chars-length");

        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromEnvFile($this->tmpDir . '/.env')
            ->load();

        $this->assertSame('Clean', $config->string('APP_NAME'));
        $this->assertSame(99, $config->int('MAX_COUNT'));
    }

    public function test_required_key_missing_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        ConfigLoader::create()
            ->fromDefaults()
            ->load();
    }

    public function test_has_and_all(): void
    {
        $config = ConfigLoader::create()
            ->fromDefaults()
            ->fromArray(['APP_KEY' => 'secret-key-32-chars-minimum!!'])
            ->load();

        $this->assertTrue($config->has('APP_NAME'));
        $this->assertFalse($config->has('UNDEFINED'));
        $this->assertArrayHasKey('APP_NAME', $config->all());
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
