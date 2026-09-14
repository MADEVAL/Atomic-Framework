<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AppUserProviderIncludeRegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        Auth::reset();
    }

    public function test_configured_user_provider_registration_survives_prior_config_include(): void
    {
        $app = new App(\Base::instance());

        // Positive control: an explicit provider class is resolved and wired.
        $app->register_user_provider(AppUserProviderIncludeRegressionStub::class);
        $this->assertTrue(Auth::instance()->has_user_provider());
        Auth::reset();

        $config = require_once ATOMIC_CONFIG . 'providers.php';
        $this->assertIsArray($config);
        class_alias(
            AppUserProviderIncludeRegressionStub::class,
            'App\\Auth\\UserProvider'
        );

        // This is the application bootstrap path: the provider class comes
        // from config/providers.php rather than an explicit argument.
        $app->register_user_provider();

        $this->assertTrue(
            Auth::instance()->has_user_provider(),
            'The configured user provider must still be registered after providers.php was included earlier.'
        );
    }
}

final class AppUserProviderIncludeRegressionStub implements UserProviderInterface
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
