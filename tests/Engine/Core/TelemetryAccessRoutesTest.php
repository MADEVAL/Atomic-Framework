<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\Telemetry;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class TelemetryAccessRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        ReflectionHelper::set(MiddlewareStack::class, 'aliases', []);
        ReflectionHelper::set(MiddlewareStack::class, 'route_map', []);
    }

    private function loadTelemetryRoutes(): void
    {
        (function () {
            $atomic = $this;
            require ATOMIC_ENGINE . 'Atomic/Core/Routes/telemetry.php';
        })->call(\Engine\Atomic\Core\App::instance());
    }

    public function test_telemetry_routes_register_access_and_role_middleware(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('TELEMETRY_ACCESS_MODE', 'config');
        $this->loadTelemetryRoutes();

        $route_map = ReflectionHelper::get(MiddlewareStack::class, 'route_map');

        $access = ['access:telemetry', 'role:admin'];

        $this->assertSame($access, $route_map['GET /telemetry'] ?? null);
        $this->assertSame($access, $route_map['POST /telemetry'] ?? null);

        foreach ([
            '/telemetry/logs',
            '/telemetry/log-channels',
            '/telemetry/log-stat',
            '/telemetry/events/@driver/@job_uuid',
            '/telemetry/dashboard',
            '/telemetry/hive',
            '/telemetry/dumps/@dump_id',
        ] as $route) {
            $this->assertSame($access, $route_map[$route] ?? null, "Route {$route} is not access protected.");
        }
    }

    public function test_telemetry_routes_can_use_auth_system_roles_without_config_user_access(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('TELEMETRY_ACCESS_MODE', 'auth');
        $this->loadTelemetryRoutes();

        $route_map = ReflectionHelper::get(MiddlewareStack::class, 'route_map');

        $this->assertSame(['role:admin'], $route_map['GET /telemetry'] ?? null);
        $this->assertSame(['role:admin'], $route_map['POST /telemetry'] ?? null);
        $this->assertSame(['role:admin'], $route_map['/telemetry/dashboard'] ?? null);
    }

    public function test_telemetry_routes_use_configured_roles(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('TELEMETRY_ACCESS_MODE', 'auth');
        $app->set('TELEMETRY_ACCESS_ALLOWED_ROLES', ['admin', 'support']);
        $this->loadTelemetryRoutes();

        $route_map = ReflectionHelper::get(MiddlewareStack::class, 'route_map');

        $this->assertSame(['role:admin,support'], $route_map['GET /telemetry'] ?? null);
        $this->assertSame(['role:admin,support'], $route_map['POST /telemetry'] ?? null);
        $this->assertSame(['role:admin,support'], $route_map['/telemetry/dashboard'] ?? null);
    }

    public function test_telemetry_routes_can_be_public(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->set('TELEMETRY_ACCESS_MODE', 'none');
        $this->loadTelemetryRoutes();

        $route_map = ReflectionHelper::get(MiddlewareStack::class, 'route_map');

        $this->assertArrayNotHasKey('GET /telemetry', $route_map);
        $this->assertArrayNotHasKey('POST /telemetry', $route_map);
        $this->assertArrayNotHasKey('/telemetry/dashboard', $route_map);
    }

    public function test_telemetry_routes_are_public_by_default(): void
    {
        $app = \Engine\Atomic\Core\App::instance();
        $app->clear('TELEMETRY_ACCESS_MODE');
        $this->loadTelemetryRoutes();

        $route_map = ReflectionHelper::get(MiddlewareStack::class, 'route_map');

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
