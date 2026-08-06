<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

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
}
