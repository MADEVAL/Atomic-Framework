<?php
declare(strict_types=1);
namespace Tests\Engine\Core;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Hook\System;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

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

        $listeners = $atomic->get('EVENTS.SESSION_STARTED');

        $this->assertIsArray($listeners);
        $this->assertCount(1, $listeners[10] ?? []);
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
