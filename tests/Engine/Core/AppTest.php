<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\Hook\ApplicationHook;
use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::instance();
    }

    public function test_detect_request_type_returns_web_by_default(): void
    {
        $this->app->atomic()->set('PATH', '/dashboard');
        $this->app->atomic()->set('CLI', false);
        $this->assertSame('web', $this->app->detect_request_type());
    }

    public function test_detect_request_type_returns_api(): void
    {
        $this->app->atomic()->set('PATH', '/api/users');
        $this->app->atomic()->set('CLI', false);
        $this->assertSame('api', $this->app->detect_request_type());
    }

    public function test_detect_request_type_returns_telemetry(): void
    {
        $this->app->atomic()->set('PATH', '/telemetry/stats');
        $this->app->atomic()->set('CLI', false);
        $this->assertSame('telemetry', $this->app->detect_request_type());
    }

    public function test_detect_request_type_returns_cli(): void
    {
        $this->app->atomic()->set('CLI', true);
        $this->assertSame('cli', $this->app->detect_request_type());
    }

    public function test_register_middleware_registers_default_aliases(): void
    {
        $this->app->register_middleware();
        $this->assertNotNull(MiddlewareStack::resolve('access'));
        $this->assertNotNull(MiddlewareStack::resolve('role'));
        $this->assertNotNull(MiddlewareStack::resolve('csrf'));
        $this->assertNotNull(MiddlewareStack::resolve('ratelimit'));
    }

    public function test_route_registers_middleware_for_route(): void
    {
        $this->app->register_middleware();
        $pattern = 'GET /test-route-' . uniqid();
        $this->app->route($pattern, 'Engine\Atomic\App\Controller->test', ['access']);
        $this->assertNotNull(MiddlewareStack::resolve('access'));
    }

    public function test_config_loaded_fires_hook(): void
    {
        $fired = false;
        Hook::instance()->add_action(
            ApplicationHook::CONFIG_LOADED,
            function () use (&$fired) { $fired = true; }
        );
        $this->app->config_loaded('env');
        $this->assertTrue($fired);
    }

    public function test_register_exception_handler_does_not_throw(): void
    {
        $this->app->register_exception_handler();
        $this->assertTrue(true);
    }

    public function test_cors_apply_with_defaults(): void
    {
        $this->app->atomic()->set('CORS', [
            'headers' => 'Content-Type',
            'origin' => '*',
            'credentials' => false,
            'expose' => '',
            'ttl' => 0,
        ]);
        $this->app->atomic()->set('HEADERS.Origin', '');
        ReflectionHelper::invoke($this->app, 'apply_cors');
        $this->assertTrue(true);
    }

    public function test_cors_rejects_foreign_domain_with_str_ends_with_bypass(): void
    {
        $this->app->atomic()->set('DOMAIN', 'example.com');
        $this->app->atomic()->set('CORS', [
            'headers' => 'Content-Type',
            'origin' => '*',
            'credentials' => true,
            'expose' => '',
            'ttl' => 0,
        ]);
        $this->app->atomic()->set('HEADERS.Origin', 'https://notexample.com');
        $this->app->atomic()->set('VERB', 'GET');

        ReflectionHelper::invoke($this->app, 'apply_cors');

        $cors_header = '';
        foreach (xdebug_get_headers() as $header) {
            if (str_starts_with($header, 'Access-Control-Allow-Origin:')) {
                $cors_header = $header;
                break;
            }
        }
        $this->assertStringContainsString(
            'Access-Control-Allow-Origin: *',
            $cors_header,
            'Foreign domain notexample.com must NOT be allowed when DOMAIN=example.com'
        );
    }

    public function test_before_server_start_runs_once(): void
    {
        $called = false;
        Hook::instance()->add_action(
            ApplicationHook::BEFORE_SERVER_START,
            function () use (&$called) { $called = true; }
        );
        $this->app->before_server_start();
        $this->assertTrue($called);

        $called = false;
        $this->app->before_server_start();
        $this->assertFalse($called, 'Should not fire twice');
    }
}
