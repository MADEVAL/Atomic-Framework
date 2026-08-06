<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Services\AuthSessionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthSessionServiceTest extends TestCase
{
    private AppContextAdapter&MockObject           $app;
    private PhpSessionAdapter&MockObject           $php_session;
    private SessionDriverFactoryAdapter&MockObject $session_factory;
    private SystemClockAdapter&MockObject          $clock;
    private IdValidatorAdapter&MockObject          $id_validator;
    private LogAdapter&MockObject                  $logger;
    private AuthSessionService                     $service;

    protected function setUp(): void
    {
        $this->app             = $this->createMock(AppContextAdapter::class);
        $this->php_session     = $this->createMock(PhpSessionAdapter::class);
        $this->session_factory = $this->createMock(SessionDriverFactoryAdapter::class);
        $this->clock           = $this->createMock(SystemClockAdapter::class);
        $this->id_validator    = $this->createMock(IdValidatorAdapter::class);
        $this->logger          = $this->createMock(LogAdapter::class);

        $this->service = new AuthSessionService(
            $this->app,
            $this->php_session,
            $this->session_factory,
            $this->clock,
            $this->id_validator,
            $this->logger,
        );
    }

    public function test_start_for_user_starts_backend_and_sets_auth_data(): void
    {
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION_CONFIG.driver', 'db'],
        ]);
        $this->php_session->method('status')->willReturn(PHP_SESSION_NONE);
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->session_factory->expects($this->once())->method('start');

        $set_calls = [];
        $this->app->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$set_calls): void { $set_calls[$k] = $v; }
        );

        $this->service->start_for_user($uuid);

        $this->assertSame($uuid, $set_calls['SESSION.user_uuid'] ?? null);
        $this->assertSame(1_700_000_000, $set_calls['SESSION.created_at'] ?? null);
    }

    public function test_validate_auth_session_keeps_non_auth_session_data_when_no_stored_uuid(): void
    {
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn(null);
        $this->app->expects($this->never())->method('clear');
        $this->app->expects($this->never())->method('set');

        $this->service->validate_auth_session();
    }

    public function test_validate_auth_session_clears_auth_keys_when_stored_uuid_is_invalid(): void
    {
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn('not-a-valid-uuid');
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(false);

        $clear_calls = [];
        $this->app->method('clear')->willReturnCallback(
            function (string $k) use (&$clear_calls): void { $clear_calls[] = $k; }
        );

        $this->service->validate_auth_session();

        $this->assertSame([
            'SESSION.user_uuid',
            'SESSION.created_at',
            'SESSION.admin_uuid',
        ], $clear_calls);
    }

    public function test_validate_auth_session_clears_auth_keys_when_session_is_expired(): void
    {
        $valid_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid',       $valid_uuid],
            ['SESSION.created_at',      1_000_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->clock->method('now')->willReturn(2_000_000_000);

        $clear_calls = [];
        $this->app->method('clear')->willReturnCallback(
            function (string $k) use (&$clear_calls): void { $clear_calls[] = $k; }
        );

        $this->service->validate_auth_session();

        $this->assertContains('SESSION.user_uuid', $clear_calls);
        $this->assertContains('SESSION.created_at', $clear_calls);
        $this->assertContains('SESSION.admin_uuid', $clear_calls);
    }

    public function test_validate_auth_session_keeps_session_when_valid_and_not_expired(): void
    {
        $valid_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid',       $valid_uuid],
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->clock->method('now')->willReturn(1_700_003_600);
        $this->app->expects($this->never())->method('clear');
        $this->app->expects($this->never())->method('set');

        $this->service->validate_auth_session();
    }

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
        $this->clock->method('now')->willReturn(1_700_003_600);

        $this->assertFalse($this->service->is_expired());
    }

    public function test_is_expired_returns_true_when_past_lifetime(): void
    {
        $this->app->method('get')->willReturnMap([
            ['SESSION.created_at',      1_700_000_000],
            ['SESSION_CONFIG.lifetime', 7200],
        ]);
        $this->clock->method('now')->willReturn(1_700_010_000);

        $this->assertTrue($this->service->is_expired());
    }

    public function test_is_started_returns_true_when_session_active(): void
    {
        $this->php_session->method('status')->willReturn(PHP_SESSION_ACTIVE);

        $this->assertTrue($this->service->is_started());
    }

    public function test_destroy_clears_only_auth_session_keys(): void
    {
        $clear_calls = [];
        $this->app->method('clear')->willReturnCallback(
            function (string $k) use (&$clear_calls): void { $clear_calls[] = $k; }
        );

        $this->service->destroy();

        $this->assertSame([
            'SESSION.user_uuid',
            'SESSION.created_at',
            'SESSION.admin_uuid',
        ], $clear_calls);
    }
}
