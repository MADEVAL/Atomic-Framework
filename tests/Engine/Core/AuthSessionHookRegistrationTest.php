<?php
declare(strict_types=1);
namespace Tests\Engine\Core;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;
use Engine\Atomic\Event\Event;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Hook\System;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthSessionHookRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        Hook::instance()->remove_action('SESSION_STARTED');
    }

    protected function tearDown(): void
    {
        Hook::instance()->remove_action('SESSION_STARTED');
    }

    public function test_system_init_does_not_register_auth_session_listener(): void
    {
        System::instance()->init();

        $this->assertFalse(Hook::instance()->has_action('SESSION_STARTED'));
    }

    public function test_register_user_provider_registers_auth_session_listener_once(): void
    {
        $atomic = \Base::instance();
        App::instance($atomic)
            ->register_user_provider(TestUserProvider::class)
            ->register_user_provider(TestUserProvider::class);

        $this->assertTrue(
            Event::instance()->has('SESSION_STARTED'),
            'SESSION_STARTED event should be registered after user provider is set'
        );
    }

    public function test_session_started_listener_is_registered_during_register_phase(): void
    {
        $container = new \Engine\Atomic\Core\Container();
        \Engine\Atomic\Core\Container::setGlobal($container);

        try {
            $probe = new AuthSessionHookProbeProvider();

            $container->instance(\Engine\Atomic\Core\App::class, \Engine\Atomic\Core\App::instance());

            $app = new \Engine\Atomic\Core\Application($container);
            $app->registerProvider($probe); // boots BEFORE AuthServiceProvider
            $app->registerProvider(new \Engine\Atomic\Core\Providers\AuthServiceProvider());
            $app->boot();

            $this->assertTrue(
                $probe->listenerPresent,
                'SESSION_STARTED listener must be registered during the register phase so it fires on the first session start.'
            );
        } finally {
            \Engine\Atomic\Core\Container::setGlobal(null);
        }
    }
}

final class AuthSessionHookProbeProvider extends \Engine\Atomic\Core\ServiceProvider
{
    public bool $listenerPresent = false;

    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->listenerPresent = Event::instance()->has('SESSION_STARTED');
    }
}

final class TestUserProvider implements UserProviderInterface
{
    public function find_by_credentials(array $credentials): ?AuthenticatableInterface
    {
        return null;
    }

    public function find_by_id(string $auth_id): ?AuthenticatableInterface
    {
        return null;
    }
}
