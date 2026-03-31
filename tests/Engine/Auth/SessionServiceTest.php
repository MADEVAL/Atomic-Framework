<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Services\SessionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SessionServiceTest extends TestCase
{
    private AppContextAdapter&MockObject           $app;
    private PhpSessionAdapter&MockObject           $php_session;
    private SessionDriverFactoryAdapter&MockObject $session_factory;
    private SystemClockAdapter&MockObject          $clock;
    private IdValidatorAdapter&MockObject          $id_validator;
    private LogAdapter&MockObject                  $logger;
    private SessionService                         $service;

    protected function setUp(): void
    {
        $this->app             = $this->createMock(AppContextAdapter::class);
        $this->php_session     = $this->createMock(PhpSessionAdapter::class);
        $this->session_factory = $this->createMock(SessionDriverFactoryAdapter::class);
        $this->clock           = $this->createMock(SystemClockAdapter::class);
        $this->id_validator    = $this->createMock(IdValidatorAdapter::class);
        $this->logger          = $this->createMock(LogAdapter::class);

        $this->service = new SessionService(
            $this->app,
            $this->php_session,
            $this->session_factory,
            $this->clock,
            $this->id_validator,
            $this->logger,
        );
    }

    // ── init ──────────────────────────────────────────────────────────────────

    public function test_init_sets_session_name_and_returns_early_when_no_cookie(): void
    {
        $this->app->method('get')->with('SESSION_CONFIG.cookie')->willReturn('my_sess');
        $this->php_session->expects($this->once())->method('name')->with('my_sess');
        $this->php_session->method('has_cookie')->with('my_sess')->willReturn(false);
        $this->session_factory->expects($this->never())->method('start');

        $this->service->init();
    }

    public function test_init_calls_start_when_cookie_exists(): void
    {
        $valid_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.cookie',   'my_sess'],
            ['SESSION_CONFIG.driver',   'db'],
            ['SESSION.user_uuid',       $valid_uuid],
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->php_session->method('name');
        $this->php_session->method('has_cookie')->willReturn(true);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->clock->method('now')->willReturn(1_700_003_600);
        $this->session_factory->expects($this->once())->method('start');

        $this->service->init();
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function test_start_with_uuid_delegates_to_factory_and_sets_session_data(): void
    {
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->with('SESSION_CONFIG.driver')->willReturn('db');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->session_factory->expects($this->once())->method('start');

        $set_calls = [];
        $this->app->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$set_calls): void { $set_calls[$k] = $v; }
        );

        $this->service->start($uuid);

        $this->assertSame($uuid, $set_calls['SESSION.user_uuid'] ?? null);
        $this->assertSame(1_700_000_000, $set_calls['SESSION.created_at'] ?? null);
    }

    public function test_start_without_uuid_destroys_when_no_stored_uuid(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver', 'db'],
            ['SESSION.user_uuid',     null],
        ]);
        $this->session_factory->method('start');
        $this->app->expects($this->once())->method('clear')->with('SESSION');

        $this->service->start();
    }

    public function test_start_without_uuid_destroys_when_stored_uuid_is_invalid(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver', 'db'],
            ['SESSION.user_uuid',     'not-a-valid-uuid'],
        ]);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(false);
        $this->session_factory->method('start');
        $this->app->expects($this->once())->method('clear')->with('SESSION');

        $this->service->start();
    }

    public function test_start_without_uuid_destroys_when_session_is_expired(): void
    {
        $valid_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver',   'db'],
            ['SESSION.user_uuid',       $valid_uuid],
            ['SESSION.created_at',      1_000_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->clock->method('now')->willReturn(2_000_000_000);
        $this->session_factory->method('start');
        $this->app->expects($this->once())->method('clear')->with('SESSION');

        $this->service->start();
    }

    public function test_start_without_uuid_keeps_session_when_valid_and_not_expired(): void
    {
        $valid_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver',   'db'],
            ['SESSION.user_uuid',       $valid_uuid],
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->clock->method('now')->willReturn(1_700_003_600); // +1h, within 2h lifetime
        $this->session_factory->method('start');
        $this->app->expects($this->never())->method('clear');

        $this->service->start();
    }

    // ── is_expired ────────────────────────────────────────────────────────────

    public function test_is_expired_returns_true_when_created_at_is_null(): void
    {
        $this->app->method('get')->with('SESSION.created_at')->willReturn(null);

        $this->assertTrue($this->service->is_expired());
    }

    public function test_is_expired_returns_false_when_within_lifetime(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->clock->method('now')->willReturn(1_700_003_600); // +1h, within 2h

        $this->assertFalse($this->service->is_expired());
    }

    public function test_is_expired_returns_true_when_past_lifetime(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->clock->method('now')->willReturn(1_700_010_000); // +2.77h, past 2h

        $this->assertTrue($this->service->is_expired());
    }

    public function test_is_expired_uses_default_lifetime_of_7200_when_not_configured(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', null],
        ]);
        $this->clock->method('now')->willReturn(1_700_007_201); // just past default 7200s

        $this->assertTrue($this->service->is_expired());
    }

    // ── is_started ────────────────────────────────────────────────────────────

    public function test_is_started_returns_true_when_session_active(): void
    {
        $this->php_session->method('status')->willReturn(PHP_SESSION_ACTIVE);

        $this->assertTrue($this->service->is_started());
    }

    public function test_is_started_returns_false_when_no_session(): void
    {
        $this->php_session->method('status')->willReturn(PHP_SESSION_NONE);

        $this->assertFalse($this->service->is_started());
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_clears_session_namespace(): void
    {
        $this->app->expects($this->once())->method('clear')->with('SESSION');

        $this->service->destroy();
    }
}
