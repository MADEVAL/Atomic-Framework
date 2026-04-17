<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use PHPUnit\Framework\TestCase;

class MiddlewarePassStub implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        return true;
    }
}

class MiddlewareBlockStub implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        return false;
    }
}

class MiddlewareParamStub implements MiddlewareInterface
{
    public string $param;

    public function __construct(?string $param = null)
    {
        $this->param = $param ?? 'default';
    }

    public function handle(\Base $atomic): bool
    {
        return true;
    }
}

class NotMiddlewareStub
{
    // Not implementing MiddlewareInterface
}

class MiddlewareStackTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static state
        $ref = new \ReflectionClass(MiddlewareStack::class);
        $aliases = $ref->getProperty('aliases');        $aliases->setValue(null, []);

        $routeMap = $ref->getProperty('routeMap');        $routeMap->setValue(null, []);
    }

    public function test_register_alias(): void
    {
        MiddlewareStack::register_alias('pass', MiddlewarePassStub::class);

        $instance = MiddlewareStack::resolve('pass');
        $this->assertInstanceOf(MiddlewarePassStub::class, $instance);
    }

    public function test_resolve_unknown_alias(): void
    {
        $this->assertNull(MiddlewareStack::resolve('unknown'));
    }

    public function test_resolve_non_middleware_class(): void
    {
        MiddlewareStack::register_alias('bad', NotMiddlewareStub::class);
        $this->assertNull(MiddlewareStack::resolve('bad'));
    }

    public function test_resolve_with_parameter(): void
    {
        MiddlewareStack::register_alias('param', MiddlewareParamStub::class);

        $instance = MiddlewareStack::resolve('param:test_value');
        $this->assertInstanceOf(MiddlewareParamStub::class, $instance);
        $this->assertSame('test_value', $instance->param);
    }

    public function test_for_route_and_run(): void
    {
        MiddlewareStack::register_alias('pass', MiddlewarePassStub::class);
        MiddlewareStack::for_route('GET /test', ['pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/test');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertTrue($result);
    }

    public function test_run_blocking_middleware(): void
    {
        MiddlewareStack::register_alias('block', MiddlewareBlockStub::class);
        MiddlewareStack::for_route('GET /blocked', ['block']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/blocked');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertFalse($result);
    }

    public function test_run_no_middleware(): void
    {
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/no-middleware');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertTrue($result);
    }

    public function test_extract_url_pattern(): void
    {
        MiddlewareStack::register_alias('pass', MiddlewarePassStub::class);
        MiddlewareStack::for_route('GET|POST /account/settings', ['pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/account/settings');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertTrue($result);
    }

    public function test_multiple_middleware_chain(): void
    {
        MiddlewareStack::register_alias('pass', MiddlewarePassStub::class);
        MiddlewareStack::for_route('GET /chain', ['pass', 'pass', 'pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/chain');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertTrue($result);
    }

    public function test_chain_stops_on_block(): void
    {
        MiddlewareStack::register_alias('pass', MiddlewarePassStub::class);
        MiddlewareStack::register_alias('block', MiddlewareBlockStub::class);
        MiddlewareStack::for_route('GET /stop', ['pass', 'block', 'pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/stop');

        $result = MiddlewareStack::run_for_route($atomic);
        $this->assertFalse($result);
    }
}
