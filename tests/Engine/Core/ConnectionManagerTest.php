<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\TestConfig;

final class ConnectionManagerTest extends TestCase
{
    public function test_sanitize_dsn_value_strips_dangerous_chars(): void
    {
        $input = 'host[name];port=3306;dbname=test';
        $result = ReflectionHelper::invoke(ConnectionManager::instance(), 'sanitize_dsn_value', [$input]);

        $this->assertStringNotContainsString('[', $result);
        $this->assertStringNotContainsString(']', $result);
        $this->assertStringNotContainsString(';', $result);
        $this->assertStringContainsString('hostname', $result);
        $this->assertStringContainsString('port3306', $result);
        $this->assertStringContainsString('dbnametest', $result);
    }

    public function test_sanitize_dsn_value_keeps_safe_chars(): void
    {
        $result = ReflectionHelper::invoke(ConnectionManager::instance(), 'sanitize_dsn_value', ['my-db._host:3306/test']);
        $this->assertSame('my-db._host:3306/test', $result);
    }

    public function test_backend_probes_fail_safely_for_incomplete_configuration(): void
    {
        $manager = ConnectionManager::instance();

        $this->assertFalse($manager->probe_mysql([]));

        $incomplete_mysql = TestConfig::db();
        unset($incomplete_mysql['port']);
        $this->assertFalse($manager->probe_mysql($incomplete_mysql));

        $this->assertFalse($manager->probe_redis([]));
        $this->assertFalse($manager->probe_memcached([]));
    }

    public function test_mysql_probe_uses_supplied_credentials_and_executes_query(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not loaded.');
        }

        $manager = ConnectionManager::instance();
        $config = TestConfig::db();

        $this->assertTrue($manager->probe_mysql($config));

        $invalid_credentials = $config;
        $invalid_credentials['password'] = '__atomic_invalid_password__';
        $this->assertFalse($manager->probe_mysql($invalid_credentials));
    }

    public function test_redis_probe_does_not_leak_warnings_or_replace_callers_handler(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('redis extension is not loaded.');
        }
        $warnings = [];
        $handler = static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        };
        set_error_handler($handler);
        try {
            $this->assertFalse(ConnectionManager::instance()->probe_redis([
                'host' => 'invalid.invalid', 'port' => 6379, 'password' => '', 'db' => 0,
            ]));
            $this->assertSame([], $warnings);

            trigger_error('handler restoration sentinel', E_USER_WARNING);
            $this->assertSame(['handler restoration sentinel'], $warnings);
        } finally {
            restore_error_handler();
        }
    }

    public function test_redis_probe_rejects_invalid_credentials_without_output(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('redis extension is not loaded.');
        }
        $manager = ConnectionManager::instance();
        $config = TestConfig::redis();
        if (!$manager->probe_redis($config)) {
            $this->markTestSkipped('The configured Redis test service is unavailable.');
        }
        $config['password'] = '__atomic_invalid_password__';
        ob_start();
        try {
            $this->assertFalse($manager->probe_redis($config));
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
