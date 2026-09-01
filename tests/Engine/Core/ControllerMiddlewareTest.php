<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\App\Controller;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\Theme\Theme;
use PHPUnit\Framework\TestCase;

final class ControllerMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        MiddlewareStack::register_alias('test-block', ControllerMiddlewareTest_Blocker::class);
        MiddlewareStack::for_route('GET /middleware-blocked', ['test-block']);

        $app = App::instance();
        $app->set('DOMAIN', 'http://localhost');
        $app->set('ROOT', ATOMIC_DIR . '/packages/skeleton/public');
        $app->set('ENQ_UI_FIX', ATOMIC_DIR . '/packages/skeleton/public/themes');
        $app->set('THEME.envname', 'default');
        Theme::reset();
    }

    public function test_blocked_middleware_aborts_before_route_without_throwing(): void
    {
        $atomic = App::atomic();
        $atomic->set('VERB', 'GET');
        $atomic->set('PATH', '/middleware-blocked');
        $atomic->set('PATTERN', '/middleware-blocked');

        $controller = new class extends Controller {};

        $this->assertFalse($controller->beforeroute($atomic));
    }
}

final class ControllerMiddlewareTest_Blocker implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        return false;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        return \Engine\Atomic\Http\Response::json(['error' => 'Blocked'], 403);
    }
}
