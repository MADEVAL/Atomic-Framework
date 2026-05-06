<?php
declare(strict_types=1);
namespace Tests\Engine\Session;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Session\Services\SessionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SessionServiceTest extends TestCase
{
    private AppContextAdapter&MockObject           $app;
    private PhpSessionAdapter&MockObject           $php_session;
    private SessionDriverFactoryAdapter&MockObject $session_factory;
    private LogAdapter&MockObject                  $logger;
    private SessionService                         $service;

    protected function setUp(): void
    {
        $this->app             = $this->createMock(AppContextAdapter::class);
        $this->php_session     = $this->createMock(PhpSessionAdapter::class);
        $this->session_factory = $this->createMock(SessionDriverFactoryAdapter::class);
        $this->logger          = $this->createMock(LogAdapter::class);

        $this->service = new SessionService(
            $this->app,
            $this->php_session,
            $this->session_factory,
            $this->logger,
        );
    }

    public function test_init_sets_session_name_and_returns_early_when_no_cookie(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->expectNotToPerformAssertions();
            $this->service->init();
            return;
        }

        $this->app->method('get')->with('SESSION_CONFIG.cookie')->willReturn('my_sess');
        $this->php_session->expects($this->once())->method('name')->with('my_sess');
        $this->php_session->method('has_cookie')->with('my_sess')->willReturn(false);
        $this->session_factory->expects($this->never())->method('start');

        $this->service->init();
    }

    public function test_start_delegates_to_factory_and_fires_hooks(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver', 'db'],
        ]);
        $this->php_session->method('status')->willReturn(PHP_SESSION_NONE);
        $this->session_factory->expects($this->once())->method('start');

        $before = false;
        $started = false;
        Hook::instance()->add_action('SESSION_BEFORE_START', function (SessionService $session) use (&$before): void {
            $before = $session === $this->service;
        });
        Hook::instance()->add_action('SESSION_STARTED', function (SessionService $session) use (&$started): void {
            $started = $session === $this->service;
        });

        try {
            $this->service->start();
        } finally {
            Hook::instance()->remove_action('SESSION_BEFORE_START');
            Hook::instance()->remove_action('SESSION_STARTED');
        }

        $this->assertTrue($before);
        $this->assertTrue($started);
    }

    public function test_session_before_start_can_change_driver_before_factory_starts(): void
    {
        $config = ['SESSION_CONFIG.driver' => 'db'];

        $this->app->method('get')->willReturnCallback(
            function (string $key) use (&$config): mixed { return $config[$key] ?? null; }
        );
        $this->app->method('set')->willReturnCallback(
            function (string $key, mixed $value) use (&$config): void { $config[$key] = $value; }
        );
        $this->php_session->method('status')->willReturn(PHP_SESSION_NONE);
        $started_driver = null;
        $this->session_factory->expects($this->once())->method('start')->willReturnCallback(
            function (string $driver) use (&$started_driver): void { $started_driver = $driver; }
        );

        Hook::instance()->add_action('SESSION_BEFORE_START', function (SessionService $session) use (&$config): void {
            $ref = new \ReflectionProperty($session, 'app');
            $app = $ref->getValue($session);
            $app->set('SESSION_CONFIG.driver', 'redis');
            $config['SESSION_CONFIG.driver'] = 'redis';
        });

        try {
            $this->service->start();
        } finally {
            Hook::instance()->remove_action('SESSION_BEFORE_START');
        }

        $this->assertSame('redis', $started_driver);
    }

    public function test_start_without_auth_data_never_clears_session_namespace(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver', 'db'],
        ]);
        $this->php_session->method('status')->willReturn(PHP_SESSION_NONE);
        $this->session_factory->method('start');
        $this->app->expects($this->never())->method('clear');

        $this->service->start();
    }

    public function test_is_started_returns_true_when_session_active(): void
    {
        $this->php_session->method('status')->willReturn(PHP_SESSION_ACTIVE);

        $this->assertTrue($this->service->is_started());
    }

    public function test_destroy_clears_session_namespace(): void
    {
        $this->app->expects($this->once())->method('clear')->with('SESSION');

        $this->service->destroy();
    }
}
