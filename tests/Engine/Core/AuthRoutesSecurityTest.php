<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

/**
 * Guards for the skeleton auth route security:
 * - CSRF middleware must protect every state-changing auth endpoint (П-4)
 * - password reset endpoints must be throttled (П-46)
 */
final class AuthRoutesSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            if (str_starts_with($class, $prefix)) {
                $file = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });

        ReflectionHelper::set(MiddlewareStack::class, 'aliases', []);
        ReflectionHelper::set(MiddlewareStack::class, 'route_map', []);
    }

    protected function tearDown(): void
    {
        // Loading the route files registers them in the global F3 instance.
        // Remove them so later tests (e.g. integration HTTP tests) do not
        // accidentally dispatch the skeleton controllers (which exit()).
        \Base::instance()->clear('ROUTES');
    }

    private function loadWebRoutes(): void
    {
        (function () {
            $atomic = $this;
            require ATOMIC_APP_ROUTES . 'web.php';
        })->call(\Engine\Atomic\Core\App::instance());
    }

    private function routeMap(): array
    {
        $this->loadWebRoutes();
        return ReflectionHelper::get(MiddlewareStack::class, 'route_map');
    }

    public function test_post_login_has_csrf_and_throttle_middleware(): void
    {
        $map = $this->routeMap();

        $this->assertIsArray($map['POST /login'] ?? null, 'POST /login must register middleware.');
        $this->assertContains('csrf_token', $map['POST /login']);
        $this->assertContains('throttle', $map['POST /login']);
    }

    public function test_post_register_has_csrf_and_throttle_middleware(): void
    {
        $map = $this->routeMap();

        $this->assertIsArray($map['POST /register'] ?? null);
        $this->assertContains('csrf_token', $map['POST /register']);
        $this->assertContains('throttle', $map['POST /register']);
    }

    public function test_post_logout_has_csrf_middleware(): void
    {
        $map = $this->routeMap();

        $this->assertIsArray($map['POST /logout'] ?? null);
        $this->assertContains('csrf_token', $map['POST /logout']);
    }

    public function test_post_password_reset_has_csrf_and_throttle_middleware(): void
    {
        $map = $this->routeMap();

        $this->assertIsArray($map['POST /password/reset'] ?? null);
        $this->assertContains('csrf_token', $map['POST /password/reset']);
        $this->assertContains('throttle', $map['POST /password/reset']);
    }

    public function test_post_password_reset_token_has_csrf_middleware(): void
    {
        $map = $this->routeMap();

        $this->assertIsArray($map['POST /password/reset/@token'] ?? null);
        $this->assertContains('csrf_token', $map['POST /password/reset/@token']);
    }

    public function test_safe_get_routes_do_not_require_csrf(): void
    {
        $map = $this->routeMap();

        $this->assertNotContains('csrf_token', $map['GET /login'] ?? []);
        $this->assertNotContains('csrf_token', $map['GET /register'] ?? []);
        $this->assertNotContains('csrf_token', $map['GET /password/reset'] ?? []);
    }

    public function test_auth_forms_submit_session_csrf_token(): void
    {
        foreach (['login', 'register'] as $form) {
            $template = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton'
                . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'default'
                . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . $form . '.atom.php';

            $this->assertFileExists($template, "{$form} template must exist");

            $content = (string)file_get_contents($template);
            $this->assertStringContainsString('name="_csrf_token"', $content, "{$form} form must submit the CSRF token");
            $this->assertStringContainsString('get_csrf_token()', $content, "{$form} form must render the session CSRF token");
        }
    }

    public function test_require_role_handle_returns_false_after_forbidden_response(): void
    {
        $ref = new \ReflectionMethod(\App\Http\Middleware\RequireRole::class, 'handle');
        $start = $ref->getStartLine();
        $end   = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $errorPos = strpos($source, 'send_json_error');
        $this->assertNotFalse($errorPos, 'RequireRole::handle must reject unauthorized users.');

        $afterReject = substr($source, $errorPos);
        $this->assertStringContainsString('return false', $afterReject, 'RequireRole::handle must return false after rejecting.');
        $this->assertStringContainsString(', false);', $afterReject, 'send_json_error must not terminate (exit) from middleware.');
    }
}
