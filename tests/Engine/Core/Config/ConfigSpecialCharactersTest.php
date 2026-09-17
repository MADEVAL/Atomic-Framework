<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Config;

use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;
use Engine\Atomic\Core\Config\V2\ConfigLoader as V2ConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigSpecialCharactersTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        $this->temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_config_special_' . uniqid('', true);
        mkdir($this->temp_dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp_dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temp_dir);
    }

    #[DataProvider('quoted_hash_values')]
    public function test_env_loader_preserves_hash_inside_quoted_values(string $quote): void
    {
        $password = 'p@ss#word=42!$%&*?';
        $env_file = $this->temp_dir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents(
            $env_file,
            'DB_PASSWORD=' . $quote . $password . $quote . ' # database password' . PHP_EOL
        );

        $method = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $parsed = $method->invoke(new ConfigLoader(\Base::instance()), $env_file);
        $v2_parsed = V2ConfigLoader::parseEnvFile($env_file);

        $this->assertSame($password, $parsed['DB_PASSWORD'] ?? null);
        $this->assertSame($password, $v2_parsed['DB_PASSWORD'] ?? null);
    }

    public static function quoted_hash_values(): array
    {
        return [
            'double quoted' => ['"'],
            'single quoted' => ["'"],
        ];
    }

    public function test_env_loader_preserves_other_special_characters(): void
    {
        $password = 'p@ss=word:42/\\!$%&*?[]{}()';
        $env_file = $this->temp_dir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($env_file, 'DB_PASSWORD="' . $password . '"' . PHP_EOL);

        $method = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $parsed = $method->invoke(new ConfigLoader(\Base::instance()), $env_file);

        $this->assertSame($password, $parsed['DB_PASSWORD'] ?? null);
    }

    public function test_php_loader_preserves_special_characters(): void
    {
        $password = 'p@ss#word=42!$%&*?:/\\[]{}()';
        $config = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => [
                    'driver' => 'mysql',
                    'password' => $password,
                ],
            ],
        ];
        file_put_contents(
            $this->temp_dir . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n"
        );

        $loader = new class(\Base::instance(), false) extends PhpConfigLoader {
            public function set_config_path(string $path): void
            {
                $this->config_path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        };
        $loader->set_config_path($this->temp_dir);

        $loaded = $loader->load_config('database');

        $this->assertSame($password, $loaded['connections']['mysql']['password'] ?? null);
    }
}
