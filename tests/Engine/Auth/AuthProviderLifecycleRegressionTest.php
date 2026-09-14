<?php
declare(strict_types=1);

namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;
use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthProviderLifecycleRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        Hook::instance()->remove_action('SESSION_STARTED');
    }

    protected function tearDown(): void
    {
        Hook::instance()->remove_action('SESSION_STARTED');
        Auth::reset();
    }

    public function test_configured_provider_does_not_construct_auth_service_before_session_start(): void
    {
        $auth = Auth::instance();
        $app = new App(\Base::instance());

        $app->register_user_provider(ConfiguredLifecycleRegressionUserProvider::class);

        $this->assertNull(
            ReflectionHelper::get($auth, 'service'),
            'Configuring a user provider should leave the deferred auth service unconstructed until SESSION_STARTED.'
        );

        Hook::instance()->do_action('SESSION_STARTED');

        $this->assertInstanceOf(
            \Engine\Atomic\Auth\Services\AuthService::class,
            ReflectionHelper::get($auth, 'service')
        );
    }
}

final class ConfiguredLifecycleRegressionUserProvider implements UserProviderInterface
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
