<?php

declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Application;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Core\Router;
use Engine\Atomic\Core\ServiceProvider;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private Container $container;
    private Application $app;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance(F3Bridge::class, new F3Bridge());
        $this->container->instance(Router::class, new Router($this->container));

        $this->app = new Application($this->container);
    }

    public function test_container_is_accessible(): void
    {
        $this->assertSame($this->container, $this->app->container());
    }

    public function test_base_is_accessible(): void
    {
        $this->assertInstanceOf(\Base::class, $this->app->base());
    }

    public function test_register_provider(): void
    {
        $provider = new ApplicationTest_FooProvider();
        $this->app->registerProvider($provider);

        $this->assertTrue(true); // No error = registered
    }

    public function test_boot_calls_register_and_boot_on_providers(): void
    {
        $provider = new ApplicationTest_TraceProvider();
        $this->app->registerProvider($provider);
        $this->app->boot();

        $this->assertSame(['registered', 'booted'], $provider->trace());
    }

    public function test_boot_only_registered_once(): void
    {
        $provider = new ApplicationTest_TraceProvider();
        $this->app->registerProvider($provider);

        $this->app->boot();
        $this->app->boot(); // Second call should be no-op

        $this->assertSame(['registered', 'booted'], $provider->trace());
    }

    public function test_provider_receives_container(): void
    {
        $provider = new ApplicationTest_ContainerCheckProvider();
        $this->app->registerProvider($provider);
        $this->app->boot();

        $this->assertTrue($provider->receivedContainer());
    }

    public function test_terminate_cleans_up(): void
    {
        $this->app->boot();
        $this->app->terminate();

        $this->assertTrue(true);
    }
}

final class ApplicationTest_FooProvider extends ServiceProvider {}

final class ApplicationTest_TraceProvider extends ServiceProvider
{
    private array $trace = [];

    public function register(): void { $this->trace[] = 'registered'; }
    public function boot(): void { $this->trace[] = 'booted'; }
    public function trace(): array { return $this->trace; }
}

final class ApplicationTest_ContainerCheckProvider extends ServiceProvider
{
    private bool $received = false;

    public function register(): void
    {
        $this->received = $this->container !== null;
    }

    public function receivedContainer(): bool { return $this->received; }
}
