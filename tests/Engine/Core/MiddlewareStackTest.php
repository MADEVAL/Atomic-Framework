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
        MiddlewareStack::registerAlias('pass', MiddlewarePassStub::class);

        $instance = MiddlewareStack::resolve('pass');
        $this->assertInstanceOf(MiddlewarePassStub::class, $instance);
    }

    public function test_resolve_unknown_alias(): void
    {
        $this->assertNull(MiddlewareStack::resolve('unknown'));
    }

    public function test_resolve_non_middleware_class(): void
    {
        MiddlewareStack::registerAlias('bad', NotMiddlewareStub::class);
        $this->assertNull(MiddlewareStack::resolve('bad'));
    }

    public function test_resolve_with_parameter(): void
    {
        MiddlewareStack::registerAlias('param', MiddlewareParamStub::class);

        $instance = MiddlewareStack::resolve('param:test_value');
        $this->assertInstanceOf(MiddlewareParamStub::class, $instance);
        $this->assertSame('test_value', $instance->param);
    }

    public function test_for_route_and_run(): void
    {
        MiddlewareStack::registerAlias('pass', MiddlewarePassStub::class);
        MiddlewareStack::forRoute('GET /test', ['pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/test');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertTrue($result);
    }

    public function test_run_blocking_middleware(): void
    {
        MiddlewareStack::registerAlias('block', MiddlewareBlockStub::class);
        MiddlewareStack::forRoute('GET /blocked', ['block']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/blocked');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertFalse($result);
    }

    public function test_run_no_middleware(): void
    {
        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/no-middleware');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertTrue($result);
    }

    public function test_extract_url_pattern(): void
    {
        MiddlewareStack::registerAlias('pass', MiddlewarePassStub::class);
        MiddlewareStack::forRoute('GET|POST /account/settings', ['pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/account/settings');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertTrue($result);
    }

    public function test_multiple_middleware_chain(): void
    {
        MiddlewareStack::registerAlias('pass', MiddlewarePassStub::class);
        MiddlewareStack::forRoute('GET /chain', ['pass', 'pass', 'pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/chain');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertTrue($result);
    }

    public function test_chain_stops_on_block(): void
    {
        MiddlewareStack::registerAlias('pass', MiddlewarePassStub::class);
        MiddlewareStack::registerAlias('block', MiddlewareBlockStub::class);
        MiddlewareStack::forRoute('GET /stop', ['pass', 'block', 'pass']);

        $atomic = \Base::instance();
        $atomic->set('PATTERN', '/stop');

        $result = MiddlewareStack::runForRoute($atomic);
        $this->assertFalse($result);
    }
}
