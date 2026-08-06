<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ── PSR-11 compliance ──

    public function test_implements_psr11_interface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function test_get_returns_registered_instance(): void
    {
        $obj = new \stdClass();
        $this->container->instance('obj', $obj);

        $this->assertSame($obj, $this->container->get('obj'));
    }

    public function test_has_returns_true_for_registered(): void
    {
        $this->container->instance('obj', new \stdClass());

        $this->assertTrue($this->container->has('obj'));
    }

    public function test_has_returns_false_for_unregistered(): void
    {
        $this->assertFalse($this->container->has('nope'));
    }

    public function test_get_throws_for_unregistered(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('nope');
    }

    // ── bind / singleton ──

    public function test_bind_creates_new_instance_each_call(): void
    {
        $this->container->bind('counter', \ArrayObject::class);

        $a = $this->container->get('counter');
        $b = $this->container->get('counter');

        $this->assertInstanceOf(\ArrayObject::class, $a);
        $this->assertNotSame($a, $b);
    }

    public function test_singleton_returns_same_instance(): void
    {
        $this->container->singleton('counter', \ArrayObject::class);

        $a = $this->container->get('counter');
        $b = $this->container->get('counter');

        $this->assertSame($a, $b);
    }

    public function test_singleton_with_closure(): void
    {
        $calls = 0;
        $this->container->singleton('counter', function () use (&$calls) {
            $calls++;
            return new \ArrayObject();
        });

        $this->container->get('counter');
        $this->container->get('counter');

        $this->assertSame(1, $calls);
    }

    public function test_instance_registers_prebuilt_object(): void
    {
        $obj = new \ArrayObject(['a' => 1]);
        $this->container->instance('config', $obj);

        $this->assertSame($obj, $this->container->get('config'));
    }

    // ── autowiring (make) ──

    public function test_make_resolves_class_without_deps(): void
    {
        $obj = $this->container->make(\ArrayObject::class);

        $this->assertInstanceOf(\ArrayObject::class, $obj);
    }

    public function test_make_resolves_class_with_typed_deps(): void
    {
        $this->container->singleton(\SplStack::class, \SplStack::class);

        $obj = $this->container->make(ContainerTest_HasDeps::class);

        $this->assertInstanceOf(ContainerTest_HasDeps::class, $obj);
        $this->assertInstanceOf(\SplStack::class, $obj->stack);
    }

    public function test_make_throws_for_unresolvable_param(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->container->make(ContainerTest_HasDeps::class);
        // SplStack is not registered, Container should fail
    }

    public function test_make_passes_primitive_params(): void
    {
        $obj = $this->container->make(ContainerTest_HasPrimitives::class, [
            'name' => 'test',
            'count' => 42,
        ]);

        $this->assertSame('test', $obj->name);
        $this->assertSame(42, $obj->count);
    }

    public function test_make_cache_resolver_for_repeated_calls(): void
    {
        $this->container->singleton(\SplStack::class, \SplStack::class);
        // First call builds resolver
        $a = $this->container->make(ContainerTest_HasDeps::class);
        // Second call uses cache
        $b = $this->container->make(ContainerTest_HasDeps::class);

        $this->assertNotSame($a, $b);
        $this->assertInstanceOf(ContainerTest_HasDeps::class, $b);
    }

    // ── call (method injection) ──

    public function test_call_invokes_closure_with_autowiring(): void
    {
        $result = $this->container->call(function (string $text) {
            return strtoupper($text);
        }, ['text' => 'world']);

        $this->assertSame('WORLD', $result);
    }

    public function test_call_invokes_method_with_typed_deps(): void
    {
        $this->container->singleton(\SplStack::class, \SplStack::class);

        $handler = new ContainerTest_Handler();
        $result = $this->container->call([$handler, 'handle']);

        $this->assertSame('handled', $result);
    }

    // ── alias ──

    public function test_alias_resolves_to_original(): void
    {
        $obj = new \ArrayObject();
        $this->container->instance('original', $obj);
        $this->container->alias('original', 'alias');

        $this->assertSame($obj, $this->container->get('alias'));
        $this->assertTrue($this->container->has('alias'));
    }

    // ── tagging ──

    public function test_tag_groups_multiple_services(): void
    {
        $this->container->instance('a', new \ArrayObject(['v' => 1]));
        $this->container->instance('b', new \ArrayObject(['v' => 2]));
        $this->container->tag(['a', 'b'], 'group');

        $tagged = $this->container->tagged('group');
        $this->assertCount(2, $tagged);
    }

    public function test_tagged_returns_empty_for_unknown_tag(): void
    {
        $this->assertSame([], $this->container->tagged('unknown'));
    }

    // ── lifecycle: reset / flush ──

    public function test_reset_clears_resolved_singletons_but_keeps_definitions(): void
    {
        $this->container->singleton('s', \ArrayObject::class);
        $a = $this->container->get('s');
        $this->container->reset();
        $b = $this->container->get('s');

        $this->assertNotSame($a, $b);
        $this->assertTrue($this->container->has('s'));
    }

    public function test_flush_removes_all_bindings(): void
    {
        $this->container->singleton('s', \ArrayObject::class);
        $this->container->flush();

        $this->assertFalse($this->container->has('s'));
    }

    // ── scoped ──

    public function test_scoped_isolates_bindings_from_parent(): void
    {
        $this->container->singleton('global', \ArrayObject::class);

        $result = $this->container->scoped(function (Container $c) {
            $c->singleton('local', \SplStack::class);
            $this->assertTrue($c->has('global'));  // inherits parent
            $this->assertTrue($c->has('local'));
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertTrue($this->container->has('global'));
        $this->assertFalse($this->container->has('local'));  // scoped binding gone
    }

    // ── introspection ──

    public function test_bound_returns_true_after_registration(): void
    {
        $this->assertFalse($this->container->bound('x'));
        $this->container->singleton('x', \ArrayObject::class);
        $this->assertTrue($this->container->bound('x'));
    }

    public function test_resolved_returns_true_after_get(): void
    {
        $this->container->singleton('x', \ArrayObject::class);
        $this->assertFalse($this->container->resolved('x'));
        $this->container->get('x');
        $this->assertTrue($this->container->resolved('x'));
    }
}

// ── Test fixtures ──

final class ContainerTest_HasDeps
{
    public function __construct(public \SplStack $stack) {}
}

final class ContainerTest_HasPrimitives
{
    public function __construct(public string $name, public int $count) {}
}

final class ContainerTest_Handler
{
    public function handle(\SplStack $stack): string
    {
        return 'handled';
    }
}
