<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
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
        $this->app->reset_cli_exit_code();
    }

    public function test_cli_exit_code_defaults_to_success(): void
    {
        $this->assertSame(0, $this->app->get_cli_exit_code());
    }

    public function test_cli_exit_code_cannot_be_overwritten(): void
    {
        $this->app->set_cli_exit_code(1);

        $this->assertSame(1, $this->app->get_cli_exit_code());

        $this->expectException(\LogicException::class);
        $this->app->set_cli_exit_code(2);
    }

    public function test_handle_command_returns_failure_for_unknown_command(): void
    {
        $this->app->atomic()->set('ROUTES', ['/help' => 'handler']);

        $this->assertSame(1, $this->app->handle_command(['atomic', 'what']));
    }

    public function test_register_user_provider_resolves_constructor_dependencies_from_container(): void
    {
        $container = Container::global();
        self::assertNotNull($container);

        $dependency = new InjectableUserProviderDependency();
        $container->instance(InjectableUserProviderDependency::class, $dependency);

        $app = new App(
            \Base::instance(),
            $container,
            new Output(fopen('php://memory', 'w'), fopen('php://memory', 'w')),
        );
        $app->register_user_provider(InjectableUserProvider::class);

        self::assertInstanceOf(InjectableUserProvider::class, InjectableUserProvider::$last_instance);
        self::assertSame($dependency, InjectableUserProvider::$last_instance->dependency);
    }

    public function test_output_is_resolved_from_container(): void
    {
        $container = Container::global();
        self::assertNotNull($container);

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $output = new Output($stdout, $stderr);
        $container->instance(Output::class, $output);

        $app = new App(\Base::instance(), $container);
        $app->handle_command(['atomic']);

        rewind($stderr);
        self::assertSame("Usage: php atomic <command> [options]" . PHP_EOL, stream_get_contents($stderr));
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

    public function test_security_headers_apply_default(): void
    {
        $this->app->atomic()->set('SECURITY_HEADERS.ENABLED', true);
        $this->app->atomic()->set('SECURITY_HEADERS.XFO', 'DENY');
        $this->app->atomic()->set('SECURITY_HEADERS.HSTS', '');
        $this->app->atomic()->set('SECURITY_HEADERS.CSP', '');

        ReflectionHelper::invoke($this->app, 'apply_security_headers');

        $headers = [];
        foreach (xdebug_get_headers() as $h) {
            $parts = explode(':', $h, 2);
            $headers[trim($parts[0])] = trim($parts[1] ?? '');
        }

        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);

        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertSame('DENY', $headers['X-Frame-Options']);

        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);

        $this->assertArrayHasKey('X-Permitted-Cross-Domain-Policies', $headers);
        $this->assertSame('none', $headers['X-Permitted-Cross-Domain-Policies']);

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    public function test_security_headers_hsts_when_configured(): void
    {
        $this->app->atomic()->set('SECURITY_HEADERS.ENABLED', true);
        $this->app->atomic()->set('SECURITY_HEADERS.HSTS', 'max-age=31536000; includeSubDomains');

        ReflectionHelper::invoke($this->app, 'apply_security_headers');

        $found = false;
        foreach (xdebug_get_headers() as $h) {
            if (str_starts_with($h, 'Strict-Transport-Security:')) {
                $found = true;
                $this->assertStringContainsString('max-age=31536000', $h);
                break;
            }
        }
        $this->assertTrue($found, 'HSTS header should be present when configured');
    }

    public function test_security_headers_disabled_by_config(): void
    {
        $this->app->atomic()->set('SECURITY_HEADERS.ENABLED', false);

        $before = count(xdebug_get_headers());
        ReflectionHelper::invoke($this->app, 'apply_security_headers');
        $after = count(xdebug_get_headers());

        $this->assertSame($before, $after, 'No new headers when SECURITY_HEADERS.ENABLED=false');
    }
}

final class InjectableUserProviderDependency
{
}

final class InjectableUserProvider implements UserProviderInterface
{
    public static ?self $last_instance = null;

    public function __construct(public InjectableUserProviderDependency $dependency)
    {
        self::$last_instance = $this;
    }

    public function find_by_credentials(array $credentials): ?AuthenticatableInterface
    {
        return null;
    }

    public function find_by_id(string $auth_id): ?AuthenticatableInterface
    {
        return null;
    }
}
