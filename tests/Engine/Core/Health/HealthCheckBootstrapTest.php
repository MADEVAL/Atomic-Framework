<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthCheckBootstrapTest extends TestCase
{
    public static function loaders(): array
    {
        return [['env'], ['php']];
    }

    #[DataProvider('loaders')]
    public function test_health_reports_broken_connections_without_runtime_errors(string $loader): void
    {
        [$status, $output] = $this->run_health($loader);
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('[FAIL] MySQL', $output);
        $this->assertStringContainsString('[FAIL] Redis', $output);
        $this->assertStringContainsString('Status: UNHEALTHY', $output);
        $this->assertStringNotContainsString('ConnectionManager:', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertStringNotContainsString('Warning:', $output);
    }

    public function test_health_reports_invalid_php_configuration_without_crashing(): void
    {
        [$status, $output] = $this->run_health('php', true);
        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Configuration source', $output);
        $this->assertStringContainsString('Status: UNHEALTHY', $output);
        $this->assertStringContainsString('ParseError', $output);
        $this->assertStringContainsString('unexpected token', $output);
    }

    private function run_health(string $loader, bool $broken_php = false): array
    {
        $directory = sys_get_temp_dir() . '/atomic-health-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $settings = "CACHE_DRIVER=db\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_DB=health_test\nDB_USERNAME=health_test\nDB_PASSWORD=invalid\nREDIS_HOST=127.0.0.1\nREDIS_PORT=1\nSESSION_DRIVER=redis\n";
        file_put_contents($directory . '/.env', $settings);
        file_put_contents($directory . '/app.php', $broken_php ? '<?php return [;' : '<?php return [];');
        file_put_contents($directory . '/database.php', '<?php return ' . var_export([
            'default' => 'mysql',
            'connections' => ['mysql' => ['host' => '127.0.0.1', 'port' => 1, 'db' => 'health_test', 'username' => 'health_test', 'password' => 'invalid']],
            'redis' => ['host' => '127.0.0.1', 'port' => 1],
        ], true) . ';');
        file_put_contents($directory . '/cache.php', "<?php return ['default' => 'db'];");
        file_put_contents($directory . '/session.php', "<?php return ['driver' => 'redis'];");
        $constants = [
            'ATOMIC_START' => 1, 'ATOMIC_DIR' => $directory, 'ATOMIC_ROOT' => $directory,
            'ATOMIC_ENGINE' => ATOMIC_ENGINE, 'ATOMIC_FRAMEWORK' => ATOMIC_FRAMEWORK,
            'ATOMIC_SUPPORT' => ATOMIC_SUPPORT, 'ATOMIC_CONFIG' => $directory . '/',
            'ATOMIC_ENV' => $directory . '/.env', 'ATOMIC_LOADER' => $loader,
        ];
        $code = '';
        foreach ($constants as $name => $value) {
            $code .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
        }
        $code .= 'require ' . var_export(ATOMIC_VENDOR . 'autoload.php', true) . ';';
        $code .= '\\Engine\\Atomic\\Core\\Bootstrap::boot();';
        try {
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $code, 'health'], [
                1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, null, array_merge(getenv(), ['NO_COLOR' => '1', 'FORCE_COLOR' => '0']));
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $this->assertDirectoryDoesNotExist($directory . '/storage', 'Health checks must not initialize the cache.');
            return [$status, $output];
        } finally {
            foreach (['.env', 'app.php', 'database.php', 'cache.php', 'session.php'] as $file) {
                unlink($directory . '/' . $file);
            }
            // Cache initialization must not create runtime files in diagnostic mode.
            if (count(scandir($directory)) === 2) {
                rmdir($directory);
            }
        }
    }
}
