<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Middleware;

use Engine\Atomic\Core\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

/**
 * П-45: run_for_route must not silently skip unknown middleware aliases —
 * that would be a silent loss of protection. It must fail closed.
 */
final class MiddlewareStackUnknownAliasTest extends TestCase
{
    protected function setUp(): void
    {
        ReflectionHelper::set(MiddlewareStack::class, 'aliases', []);
        ReflectionHelper::set(MiddlewareStack::class, 'route_map', []);
    }

    public function test_run_for_route_fails_closed_on_unknown_alias(): void
    {
        MiddlewareStack::register_alias('known', \Tests\Engine\Core\Middleware\PassThroughMiddleware::class);
        MiddlewareStack::for_route('GET /secure', ['known', 'typo_alias']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/secure');
        $atomic->set('VERB', 'GET');

        $this->assertFalse(
            MiddlewareStack::run_for_route($atomic),
            'An unknown middleware alias must abort the request instead of silently skipping protection.'
        );
    }

    public function test_run_for_route_passes_when_all_aliases_are_known(): void
    {
        MiddlewareStack::register_alias('known', \Tests\Engine\Core\Middleware\PassThroughMiddleware::class);
        MiddlewareStack::for_route('GET /open', ['known']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/open');
        $atomic->set('VERB', 'GET');

        $this->assertTrue(MiddlewareStack::run_for_route($atomic));
    }
}

final class PassThroughMiddleware implements \Engine\Atomic\Core\Middleware\MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        return true;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        return $next($request);
    }
}
