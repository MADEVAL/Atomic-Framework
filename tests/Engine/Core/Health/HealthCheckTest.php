<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Health;

use Engine\Atomic\Core\Health\HealthCheck;
use Engine\Atomic\Core\Health\HealthCheckRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Support\Environment;
use Tests\Support\StreamCapture;

final class HealthCheckTest extends TestCase
{
    public function test_unwritable_log_file_makes_report_unhealthy(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'log_files' => ['audit.log' => false],
        ]));
        $this->assertFalse($report['healthy']);
        $this->assertSame('fail', $this->find_check($report, 'audit.log')['status']);
    }

    public function test_missing_required_environment_requirements_make_health_check_fail(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'php' => ['current' => '8.0.30', 'required' => '8.1.0', 'status' => false],
            'required_extensions' => [
                'json' => true,
                'pdo_mysql' => false,
            ],
        ]));

        $php = $this->find_check($report, 'PHP');
        $this->assertSame('fail', $php['status']);
        $this->assertSame('Upgrade PHP to 8.1.0 or newer.', $php['suggestion']);
        $extension = $this->find_check($report, 'pdo_mysql');
        $this->assertSame('fail', $extension['status']);
        $this->assertSame('Install or enable the pdo_mysql PHP extension.', $extension['suggestion']);
        $this->assertFalse($report['healthy']);
    }

    public function test_missing_configuration_source_makes_health_check_fail(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'config_source' => ['name' => '.env', 'status' => false],
        ]));

        $source = $this->find_check($report, 'Configuration source');
        $this->assertSame('fail', $source['status']);
        $this->assertStringContainsString('missing or unreadable', $source['message']);
        $this->assertSame(
            'Create the configuration source and ensure it is readable by the application user.',
            $source['suggestion'],
        );
        $this->assertFalse($report['healthy']);
    }

    public function test_failed_mysql_connection_makes_health_check_fail(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'mysql' => ['reachable' => false, 'target' => 'mysql.internal:3307/atomic'],
        ]));

        $mysql = $this->find_check($report, 'MySQL');
        $this->assertSame('fail', $mysql['status']);
        $this->assertStringContainsString('Connection or credentials failed', $mysql['message']);
        $this->assertSame(
            'Check the MySQL host, port, database, username, password, and service availability.',
            $mysql['suggestion'],
        );
        $this->assertFalse($report['healthy']);
    }

    public function test_mysql_is_reported_before_runtime_paths(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot());
        $names = array_column($report['required'], 'name');

        $this->assertLessThan(
            array_search('storage/logs', $names, true),
            array_search('MySQL', $names, true),
        );
    }

    public function test_application_security_settings_are_reported_as_separate_checks(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'configuration_checks' => [
                'DOMAIN' => ['status' => true, 'message' => 'Configured domain is valid.', 'suggestion' => null],
                'APP_KEY' => ['status' => false, 'message' => 'APP_KEY is not configured.', 'suggestion' => 'Set APP_KEY.'],
                'APP_UUID' => ['status' => true, 'message' => 'Application UUID is valid.', 'suggestion' => null],
                'APP_ENCRYPTION_KEY' => [
                    'status' => false,
                    'message' => 'APP_ENCRYPTION_KEY is invalid.',
                    'suggestion' => 'Set a valid APP_ENCRYPTION_KEY.',
                ],
            ],
        ]));

        $this->assertSame('fail', $this->find_check($report, 'APP_KEY')['status']);
        $this->assertSame('ok', $this->find_check($report, 'APP_UUID')['status']);
        $this->assertSame('fail', $this->find_check($report, 'APP_ENCRYPTION_KEY')['status']);
        $this->assertFalse($report['healthy']);
    }

    public function test_missing_unselected_redis_is_always_a_high_priority_recommendation(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => false, 'sodium' => true, 'memcached' => false],
            'redis' => ['reachable' => false, 'target' => '127.0.0.1:6379'],
        ]));

        $redis = $this->find_check($report, 'Redis');
        $this->assertSame('warn', $redis['status']);
        $this->assertStringContainsString('Highly recommended', $redis['message']);
        $this->assertTrue($report['healthy']);
    }

    public function test_missing_selected_redis_makes_health_check_fail(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => false, 'sodium' => true, 'memcached' => false],
            'drivers' => ['cache' => 'folder', 'session' => 'redis', 'mutex' => 'db', 'queue' => 'db'],
            'redis' => ['reachable' => false, 'target' => '127.0.0.1:6379'],
        ]));

        $redis = $this->find_check($report, 'Redis');
        $this->assertSame('fail', $redis['status']);
        $this->assertSame(
            'Install or enable the redis PHP extension, or select a non-Redis driver.',
            $redis['suggestion'],
        );
        $this->assertFalse($report['healthy']);
    }

    public function test_reachable_selected_redis_passes_health_check(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => true, 'sodium' => true, 'memcached' => false],
            'drivers' => ['cache' => 'redis', 'session' => 'db', 'mutex' => 'db', 'queue' => 'db'],
            'redis' => ['reachable' => true, 'target' => 'redis.internal:6379'],
        ]));

        $redis = $this->find_check($report, 'Redis');
        $this->assertSame('ok', $redis['status']);
        $this->assertStringContainsString('redis.internal:6379', $redis['message']);
        $this->assertTrue($report['healthy']);
    }

    public function test_unreachable_selected_redis_suggests_connection_settings(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => true, 'sodium' => true, 'memcached' => false],
            'drivers' => ['cache' => 'redis', 'session' => 'db', 'mutex' => 'db', 'queue' => 'db'],
            'redis' => ['reachable' => false, 'target' => 'redis.internal:6379'],
        ]));

        $redis = $this->find_check($report, 'Redis');
        $this->assertSame('fail', $redis['status']);
        $this->assertSame(
            'Check the Redis host, port, credentials, selected database, and service availability.',
            $redis['suggestion'],
        );
    }

    public function test_unselected_memcached_is_reported_as_skipped(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot());

        $this->assertSame('skip', $this->find_check($report, 'Memcached')['status']);
    }

    public function test_selected_memcached_without_extension_makes_health_check_fail(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => false, 'sodium' => true, 'memcached' => false],
            'drivers' => ['cache' => 'memcached', 'session' => 'db', 'mutex' => 'db', 'queue' => 'db'],
        ]));

        $memcached = $this->find_check($report, 'Memcached');
        $this->assertSame('fail', $memcached['status']);
        $this->assertStringContainsString('PHP extension is not loaded', $memcached['message']);
        $this->assertSame(
            'Install or enable the memcached PHP extension, or select a non-Memcached driver.',
            $memcached['suggestion'],
        );
        $this->assertFalse($report['healthy']);
    }

    public function test_reachable_selected_memcached_passes_health_check(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => false, 'sodium' => true, 'memcached' => true],
            'drivers' => ['cache' => 'memcached', 'session' => 'db', 'mutex' => 'db', 'queue' => 'db'],
            'memcached' => ['reachable' => true, 'target' => 'cache.internal:11211'],
        ]));

        $memcached = $this->find_check($report, 'Memcached');
        $this->assertSame('ok', $memcached['status']);
        $this->assertStringContainsString('cache.internal:11211', $memcached['message']);
        $this->assertTrue($report['healthy']);
    }

    public function test_sodium_is_reported_as_optional_when_not_loaded(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'extensions' => ['redis' => false, 'sodium' => false, 'memcached' => false],
        ]));

        $this->assertSame([], array_filter(
            $report['recommended'],
            static fn(array $check): bool => $check['name'] === 'Sodium',
        ));
        $this->assertSame('warn', $this->find_check($report, 'Sodium')['status']);
        $this->assertStringContainsString('only required when Crypto is used', $this->find_check($report, 'Sodium')['message']);
        $this->assertSame(
            'Install or enable the sodium PHP extension before using Crypto.',
            $this->find_check($report, 'Sodium')['suggestion'],
        );
    }

    public function test_required_configuration_and_runtime_failures_make_report_unhealthy(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'configuration_errors' => ['DOMAIN is not configured.'],
            'configuration_checks' => [
                'DOMAIN' => [
                    'status' => false,
                    'message' => 'DOMAIN is not configured.',
                    'suggestion' => 'Set DOMAIN to the application host or HTTP(S) origin.',
                ],
            ],
            'runtime_paths' => ['storage/logs' => false],
        ]));

        $this->assertFalse($report['healthy']);
        $configuration = $this->find_check($report, 'DOMAIN');
        $this->assertSame('fail', $configuration['status']);
        $this->assertSame(
            'Set DOMAIN to the application host or HTTP(S) origin.',
            $configuration['suggestion'],
        );
        $runtime = $this->find_check($report, 'storage/logs');
        $this->assertSame('fail', $runtime['status']);
        $this->assertSame(
            'Create the directory and grant write access to the application user.',
            $runtime['suggestion'],
        );
    }

    public function test_cli_report_renders_actionable_failure_suggestions(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'required_extensions' => ['pdo_mysql' => false],
            'runtime_paths' => ['storage/logs' => false],
        ]));

        $output = HealthCheckRenderer::cli_report($report);

        $this->assertStringContainsString('Fix: Install or enable the pdo_mysql PHP extension.', $output);
        $this->assertStringContainsString(
            'Fix: Create the directory and grant write access to the application user.',
            $output,
        );
    }

    public function test_cli_report_never_skips_redis(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot());
        $output = HealthCheckRenderer::cli_report($report);

        $this->assertStringContainsString('[WARN] Redis', $output);
        $this->assertStringNotContainsString('[SKIP] Redis', $output);
        $this->assertStringContainsString('HEALTHY', $output);
    }

    public function test_cli_report_shows_unselected_memcached_as_skipped(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot());
        $output = HealthCheckRenderer::cli_report($report);

        $this->assertStringContainsString('[SKIP] Memcached', $output);
        $this->assertStringContainsString('Not selected by an active driver', $output);
    }

    public function test_cli_report_uses_output_status_colors_when_color_is_forced(): void
    {
        Environment::clear_cli_color();
        Environment::set('FORCE_COLOR', '1');
        $stream = StreamCapture::memory();
        $output = new \Engine\Atomic\CLI\Console\Output($stream, $stream);

        try {
            HealthCheckRenderer::render_cli(
                HealthCheck::evaluate_snapshot($this->healthy_snapshot()),
                $output,
            );

            $raw = StreamCapture::read($stream);
            $this->assertStringContainsString("\033[32m[OK]", $raw);
            $this->assertStringContainsString("\033[33m[WARN]", $raw);
            $this->assertStringContainsString("\033[36mPHP\033[0m", $raw);
            $this->assertStringContainsString('Memcached', $raw);
        } finally {
            Environment::clear_cli_color();
            fclose($stream);
        }
    }

    public function test_web_response_exposes_only_overall_status(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot([
            'configuration_errors' => ['APP_KEY is not configured.'],
            'configuration_checks' => [
                'APP_KEY' => [
                    'status' => false,
                    'message' => 'APP_KEY is not configured.',
                    'suggestion' => 'Set a unique APP_KEY value.',
                ],
            ],
        ]));

        $json = HealthCheckRenderer::web_json($report);

        $this->assertSame('{"status":"unhealthy"}', $json);
        $this->assertStringNotContainsString('APP_KEY', $json);
        $this->assertSame(503, HealthCheckRenderer::web_status($report));
    }

    public function test_healthy_web_response_uses_ok_status_and_http_200(): void
    {
        $report = HealthCheck::evaluate_snapshot($this->healthy_snapshot());

        $this->assertSame('{"status":"ok"}', HealthCheckRenderer::web_json($report));
        $this->assertSame(200, HealthCheckRenderer::web_status($report));
    }

    private function healthy_snapshot(array $overrides = []): array
    {
        return array_replace([
            'php' => ['current' => '8.4.0', 'required' => '8.1.0', 'status' => true],
            'required_extensions' => [
                'json' => true,
                'session' => true,
                'mbstring' => true,
                'fileinfo' => true,
                'pdo' => true,
                'pdo_mysql' => true,
                'curl' => true,
            ],
            'extensions' => ['redis' => false, 'sodium' => false, 'memcached' => false],
            'configuration_errors' => [],
            'config_source' => ['name' => '.env', 'status' => true],
            'runtime_paths' => ['storage/logs' => true, 'storage/framework/cache' => true],
            'configuration_checks' => [
                'DOMAIN' => ['status' => true, 'message' => 'Configured domain is valid.', 'suggestion' => null],
                'APP_KEY' => ['status' => true, 'message' => 'Application key is configured.', 'suggestion' => null],
                'APP_UUID' => ['status' => true, 'message' => 'Application UUID is valid.', 'suggestion' => null],
                'APP_ENCRYPTION_KEY' => [
                    'status' => true,
                    'message' => 'Application encryption key is valid.',
                    'suggestion' => null,
                ],
            ],
            'mysql' => ['reachable' => true, 'target' => '127.0.0.1:3306/atomic_test'],
            'drivers' => ['cache' => 'folder', 'session' => 'db', 'mutex' => 'db', 'queue' => 'db'],
            'redis' => ['reachable' => false, 'target' => '127.0.0.1:6379'],
            'memcached' => ['reachable' => false, 'target' => '127.0.0.1:11211'],
        ], $overrides);
    }

    private function find_check(array $report, string $name): array
    {
        foreach (array_merge($report['required'], $report['recommended'], $report['optional']) as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        $this->fail("Health check '{$name}' was not reported.");
    }
}
