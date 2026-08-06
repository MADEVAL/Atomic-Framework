<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function test_set_container_makes_container_accessible(): void
    {
        $provider = new ServiceProviderTest_FooProvider();
        $provider->setContainer($this->container);

        $this->assertTrue($provider->containerAccessible());
    }

    public function test_register_and_boot_called_in_order(): void
    {
        $provider = new ServiceProviderTest_TraceProvider();
        $provider->setContainer($this->container);

        $provider->register();
        $provider->boot();

        $this->assertSame(['registered', 'booted'], $provider->trace());
    }

    public function test_default_requires_returns_empty(): void
    {
        $provider = new ServiceProviderTest_FooProvider();

        $this->assertSame([], $provider->requires());
    }

    public function test_default_provides_returns_empty(): void
    {
        $provider = new ServiceProviderTest_FooProvider();

        $this->assertSame([], $provider->provides());
    }

    public function test_default_is_not_deferred(): void
    {
        $provider = new ServiceProviderTest_FooProvider();

        $this->assertFalse($provider->isDeferred());
    }

    public function test_deferred_provider(): void
    {
        $provider = new ServiceProviderTest_DeferredProvider();

        $this->assertTrue($provider->isDeferred());
        $this->assertSame(['SomeService'], $provider->provides());
    }

    public function test_provider_with_dependencies(): void
    {
        $provider = new ServiceProviderTest_DependentProvider();

        $this->assertSame([ServiceProviderTest_FooProvider::class], $provider->requires());
    }
}

// ── Test providers ──

final class ServiceProviderTest_FooProvider extends ServiceProvider
{
    public function containerAccessible(): bool
    {
        return $this->container !== null;
    }
}

final class ServiceProviderTest_TraceProvider extends ServiceProvider
{
    private array $trace = [];

    public function register(): void
    {
        $this->trace[] = 'registered';
    }

    public function boot(): void
    {
        $this->trace[] = 'booted';
    }

    public function trace(): array
    {
        return $this->trace;
    }
}

final class ServiceProviderTest_DeferredProvider extends ServiceProvider
{
    public function isDeferred(): bool { return true; }
    public function provides(): array { return ['SomeService']; }
}

final class ServiceProviderTest_DependentProvider extends ServiceProvider
{
    public function requires(): array { return [ServiceProviderTest_FooProvider::class]; }
}
