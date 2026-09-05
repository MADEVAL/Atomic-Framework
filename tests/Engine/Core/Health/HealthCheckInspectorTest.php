<?php

declare(strict_types=1);

namespace Tests\Engine\Core\Health;

use Engine\Atomic\Core\BootstrapConfigurationValidator;
use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Health\HealthCheckInspector;
use Engine\Atomic\Core\Prefly;
use PHPUnit\Framework\TestCase;

final class HealthCheckInspectorTest extends TestCase
{
    public function test_snapshot_uses_injected_probes_and_reports_their_results(): void
    {
        $atomic = \Base::instance();
        $settings = [
            'APP_KEY' => 'test-key',
            'APP_UUID' => '6ba7b810-9dad-41d1-80b4-00c04fd430c8',
            'APP_ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
            'DOMAIN' => 'https://example.test/',
            'DB_CONFIG' => [
                'driver' => 'mysql',
                'host' => 'mysql.test',
                'port' => '3307',
                'db' => 'atomic_test',
                'username' => 'atomic_user',
                'password' => 'secret',
            ],
            'REDIS' => ['host' => 'redis.test', 'port' => 6380],
            'MEMCACHED' => ['host' => 'memcached.test', 'port' => 11212],
            'LOGS' => '/fake/logs',
            'TEMP' => '/fake/temp',
            'FONTS' => '/fake/fonts',
            'FONTS_TEMP' => '/fake/font-cache',
            'CACHE_CONFIG' => ['default' => 'folder', 'path' => '/fake/cache'],
            'SESSION_CONFIG' => ['driver' => 'db'],
            'MUTEX' => ['driver' => 'db'],
            'QUEUE_DRIVER' => 'db',
            'LOG_CHANNELS' => [
                'default' => 'audit',
                'channels' => ['audit' => ['path' => 'missing/audit.log']],
            ],
        ];
        $original = [];
        foreach ($settings as $key => $value) {
            $original[$key] = $atomic->get($key);
            $atomic->set($key, $value);
        }

        $prefly = $this->createMock(Prefly::class);
        $prefly->method('check_environment')->willReturn([
            'php_version' => ['required' => '8.1.0', 'current' => '8.4.0', 'status' => true],
            'extensions' => ['json' => ['required' => true, 'status' => false]],
        ]);

        $connections = $this->createMock(ConnectionManager::class);
        $connections->expects($this->once())
            ->method('probe_mysql')
            ->with([
                'driver' => 'mysql',
                'host' => 'mysql.test',
                'port' => '3307',
                'db' => 'atomic_test',
                'username' => 'atomic_user',
                'password' => 'secret',
            ])
            ->willReturn(true);
        $connections->expects($this->once())
            ->method('probe_redis')
            ->with(['host' => 'redis.test', 'port' => 6380])
            ->willReturn(true);
        $connections->expects($this->once())
            ->method('probe_memcached')
            ->with(['host' => 'memcached.test', 'port' => 11212])
            ->willReturn(false);

        try {
            $inspector = new HealthCheckInspector(
                $atomic,
                $prefly,
                new BootstrapConfigurationValidator(),
                $connections,
                static fn(string $extension): bool => in_array($extension, ['redis', 'sodium', 'memcached'], true),
                static fn(string $path): bool => !in_array($path, ['/fake/temp', '/fake/cache'], true)
                    && !str_contains($path, 'missing'),
                static fn(): array => ['name' => '/fake/.env', 'status' => true],
            );

            $snapshot = $inspector->snapshot();

            $this->assertFalse($snapshot['required_extensions']['json']);
            $this->assertTrue($snapshot['configuration_checks']['APP_KEY']['status']);
            $this->assertTrue($snapshot['configuration_checks']['APP_ENCRYPTION_KEY']['status']);
            $this->assertTrue($snapshot['mysql']['reachable']);
            $this->assertSame('mysql.test:3307/atomic_test', $snapshot['mysql']['target']);
            $this->assertTrue($snapshot['redis']['reachable']);
            $this->assertFalse($snapshot['memcached']['reachable']);
            $this->assertTrue($snapshot['runtime_paths']['LOGS (/fake/logs)']);
            $this->assertFalse($snapshot['runtime_paths']['TEMP (/fake/temp)']);
            $this->assertFalse($snapshot['runtime_paths']['CACHE (/fake/cache)']);
            $this->assertContains(false, $snapshot['log_files']);
            $this->assertSame(['name' => '/fake/.env', 'status' => true], $snapshot['config_source']);
        } finally {
            foreach ($original as $key => $value) {
                $atomic->set($key, $value);
            }
        }
    }
}
