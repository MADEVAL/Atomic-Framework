<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\Telemetry;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;

final class TelemetryAccessRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(MiddlewareStack::class);
        $ref->getProperty('aliases')->setValue(null, []);
        $ref->getProperty('route_map')->setValue(null, []);
    }

    public function test_telemetry_routes_register_access_and_role_middleware(): void
    {
        $atomic = \Engine\Atomic\Core\App::instance();
        $atomic->set('TELEMETRY_ACCESS_MODE', 'config');
        require ATOMIC_ENGINE . 'Atomic/Core/Routes/telemetry.php';

        $ref = new \ReflectionClass(MiddlewareStack::class);
        $route_map = $ref->getProperty('route_map')->getValue();

        $viewer = ['access:telemetry', 'role:telemetry.viewer'];
        $admin = ['access:telemetry', 'role:telemetry.admin'];

        $this->assertSame($viewer, $route_map['GET /telemetry'] ?? null);
        $this->assertSame($admin, $route_map['POST /telemetry'] ?? null);

        foreach ([
            '/telemetry/logs',
            '/telemetry/log-channels',
            '/telemetry/log-stat',
            '/telemetry/events/@driver/@job_uuid',
            '/telemetry/dashboard',
            '/telemetry/hive',
            '/telemetry/dumps/@dump_id',
        ] as $route) {
            $this->assertSame($viewer, $route_map[$route] ?? null, "Route {$route} is not access protected.");
        }
    }

    public function test_telemetry_routes_can_use_auth_system_roles_without_config_user_access(): void
    {
        $atomic = \Engine\Atomic\Core\App::instance();
        $atomic->set('TELEMETRY_ACCESS_MODE', 'auth');
        require ATOMIC_ENGINE . 'Atomic/Core/Routes/telemetry.php';

        $ref = new \ReflectionClass(MiddlewareStack::class);
        $route_map = $ref->getProperty('route_map')->getValue();

        $this->assertSame(['role:telemetry.viewer'], $route_map['GET /telemetry'] ?? null);
        $this->assertSame(['role:telemetry.admin'], $route_map['POST /telemetry'] ?? null);
        $this->assertSame(['role:telemetry.viewer'], $route_map['/telemetry/dashboard'] ?? null);
    }

    public function test_telemetry_routes_can_be_public(): void
    {
        $atomic = \Engine\Atomic\Core\App::instance();
        $atomic->set('TELEMETRY_ACCESS_MODE', 'none');
        require ATOMIC_ENGINE . 'Atomic/Core/Routes/telemetry.php';

        $ref = new \ReflectionClass(MiddlewareStack::class);
        $route_map = $ref->getProperty('route_map')->getValue();

        $this->assertArrayNotHasKey('GET /telemetry', $route_map);
        $this->assertArrayNotHasKey('POST /telemetry', $route_map);
        $this->assertArrayNotHasKey('/telemetry/dashboard', $route_map);
    }

    public function test_telemetry_routes_are_public_by_default(): void
    {
        $atomic = \Engine\Atomic\Core\App::instance();
        $atomic->clear('TELEMETRY_ACCESS_MODE');
        require ATOMIC_ENGINE . 'Atomic/Core/Routes/telemetry.php';

        $ref = new \ReflectionClass(MiddlewareStack::class);
        $route_map = $ref->getProperty('route_map')->getValue();

        $this->assertArrayNotHasKey('GET /telemetry', $route_map);
        $this->assertArrayNotHasKey('/telemetry/dashboard', $route_map);
    }

    public function test_telemetry_before_route_uses_parent(): void
    {
        $method = new \ReflectionMethod(Telemetry::class, 'beforeroute');
        $body = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('parent::beforeroute($atomic)', $body);
    }
}
