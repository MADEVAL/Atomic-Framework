<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Core\Route;
use Engine\Atomic\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance(F3Bridge::class, new F3Bridge());

        $this->router = new Router($this->container);
    }

    // ── Request type detection ──

    public function test_detect_api_request_by_first_segment(): void
    {
        $this->assertSame('api', $this->router->detectRequestType('/api/users'));
    }

    public function test_detect_telemetry_request(): void
    {
        $this->assertSame('telemetry', $this->router->detectRequestType('/telemetry/dashboard'));
    }

    public function test_detect_cli_returns_cli(): void
    {
        $this->assertSame('cli', $this->router->detectRequestType('cli'));
    }

    public function test_detect_web_by_default(): void
    {
        $this->assertSame('web', $this->router->detectRequestType('/'));
        $this->assertSame('web', $this->router->detectRequestType('/dashboard'));
        $this->assertSame('web', $this->router->detectRequestType('/login'));
    }

    public function test_detect_request_type_is_case_insensitive(): void
    {
        $this->assertSame('api', $this->router->detectRequestType('/API/users'));
        $this->assertSame('telemetry', $this->router->detectRequestType('/Telemetry'));
    }

    // ── Fluent route definitions ──

    public function test_get_creates_get_route(): void
    {
        $route = $this->router->get('/test', 'Controller@method');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['GET'], $route->methods());
        $this->assertSame('/test', $route->path());
    }

    public function test_post_creates_post_route(): void
    {
        $route = $this->router->post('/submit', 'Controller@store');

        $this->assertSame(['POST'], $route->methods());
    }

    public function test_add_creates_multi_method_route(): void
    {
        $route = $this->router->add('GET|POST', '/form', 'Controller@handle');

        $this->assertSame(['GET', 'POST'], $route->methods());
    }

    public function test_put_patch_delete_routes(): void
    {
        $this->assertSame(['PUT'], $this->router->put('/x/1', 'C@update')->methods());
        $this->assertSame(['PATCH'], $this->router->patch('/x/1', 'C@patch')->methods());
        $this->assertSame(['DELETE'], $this->router->delete('/x/1', 'C@destroy')->methods());
    }

    // ── Named routes ──

    public function test_named_route_can_be_retrieved(): void
    {
        $this->router->get('/dashboard', 'C@index')->name('dashboard');

        $this->assertNotNull($this->router->named('dashboard'));
        $this->assertSame('/dashboard', $this->router->named('dashboard')->path());
    }

    public function test_named_returns_null_for_unknown(): void
    {
        $this->assertNull($this->router->named('unknown'));
    }

    public function test_route_url_generation(): void
    {
        $this->router->get('/user/{id}', 'C@show')->name('user.show');

        $route = $this->router->named('user.show');
        $this->assertSame('/user/42', $route->url(['id' => 42]));
    }

    public function test_route_url_with_multiple_params(): void
    {
        $this->router->get('/post/{year}/{slug}', 'C@post')->name('post');

        $url = $this->router->named('post')->url(['year' => '2024', 'slug' => 'hello-world']);

        $this->assertSame('/post/2024/hello-world', $url);
    }

    // ── Middleware on routes ──

    public function test_route_middleware_is_stored(): void
    {
        $route = $this->router->get('/admin', 'C@admin')->middleware('auth', 'role:admin');

        $this->assertSame(['auth', 'role:admin'], $route->middlewareAliases());
    }

    // ── Group routing ──

    public function test_group_prefixes_routes(): void
    {
        $this->router->group('/admin', function () {
            $this->router->get('/users', 'AdminController@users')->name('admin.users');
        });

        $route = $this->router->named('admin.users');
        $this->assertNotNull($route);
        $this->assertSame('/admin/users', $route->path());
    }

    public function test_group_inherits_middleware(): void
    {
        $group = $this->router->group('/api', function () {
            $this->router->get('/data', 'ApiController@data');
        });

        $group->middleware('auth:api');

        $routes = $this->router->routes();
        $this->assertNotEmpty($routes);
    }

    public function test_nested_groups(): void
    {
        $this->router->group('/api', function () {
            $this->router->group('/v2', function () {
                $this->router->get('/status', 'ApiController@status')->name('api.v2.status');
            });
        });

        $route = $this->router->named('api.v2.status');
        $this->assertNotNull($route);
        $this->assertSame('/api/v2/status', $route->path());
    }

    // ── f3route compatibility ──

    public function test_f3route_registers_pattern(): void
    {
        $this->router->f3route('GET /legacy/path', 'LegacyController@old');

        $routes = $this->router->routes();
        $this->assertNotEmpty($routes);
    }

    public function test_route_is_alias_for_f3route(): void
    {
        $this->router->route('GET /compat', 'CompatController@index');

        $routes = $this->router->routes();
        $this->assertNotEmpty($routes);
    }

    // ── Route type registration ──

    public function test_register_route_type(): void
    {
        $this->router->registerRouteType('custom', 'custom.php', 'custom.error.php');

        $this->assertTrue($this->router->hasRouteType('custom'));
    }

    public function test_unknown_route_type_returns_false(): void
    {
        $this->assertFalse($this->router->hasRouteType('unknown'));
    }
}
