<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Application;
use Engine\Atomic\Core\Bootstrap;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\ServiceProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BootstrapProviderIncludeRegressionTest extends TestCase
{
    public function test_application_provider_registration_survives_prior_config_include(): void
    {
        // The normal bootstrap/config loader can include this file before the
        // bootstrap registration phase asks for its application providers.
        $config = require_once ATOMIC_CONFIG . 'providers.php';
        $this->assertIsArray($config);

        // Prove the runtime registration seam itself is healthy; only the
        // second config include should make the configured provider disappear.
        $explicit = new Application(new Container());
        $explicit->registerProvider(new BootstrapProviderIncludeRegressionPositiveProvider());
        $this->assertCount(1, ReflectionHelper::get($explicit, 'providers'));

        // The skeleton provider is not part of the framework test autoload;
        // alias its configured name to a local no-op provider so a corrected
        // registration path can be exercised without loading application code.
        class_alias(
            BootstrapProviderIncludeRegressionPositiveProvider::class,
            'App\\Providers\\ApplicationServiceProvider'
        );

        $runtime = new Application(new Container());
        $register = new \ReflectionMethod(Bootstrap::class, 'register_application_providers');
        $register->invoke(null, $runtime);

        $providers = ReflectionHelper::get($runtime, 'providers');
        $this->assertNotEmpty($providers);
        $this->assertInstanceOf(BootstrapProviderIncludeRegressionPositiveProvider::class, $providers[0]);
    }
}

final class BootstrapProviderIncludeRegressionPositiveProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
