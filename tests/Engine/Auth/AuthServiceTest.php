<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\BcryptHasherAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\MetaStorageAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionManagerAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Adapters\TransientCacheAdapter;
use Engine\Atomic\Auth\Interfaces\AuthSessionInterface;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Auth\Services\AuthService;
use Engine\Atomic\Enums\Role;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AuthServiceTest extends TestCase
{
    private AppContextAdapter&MockObject      $app;
    private AuthSessionInterface&MockObject   $session;
    private MetaStorageAdapter&MockObject     $meta;
    private TransientCacheAdapter&MockObject  $cache;
    private LogAdapter&MockObject             $logger;
    private SystemClockAdapter&MockObject     $clock;
    private PhpSessionAdapter&MockObject      $php_session;
    private BcryptHasherAdapter&MockObject    $hasher;
    private SessionManagerAdapter&MockObject  $session_manager;
    private AuthService                        $service;

    protected function setUp(): void
    {
        $this->app             = $this->createMock(AppContextAdapter::class);
        $this->session         = $this->createMock(AuthSessionInterface::class);
        $this->meta            = $this->createMock(MetaStorageAdapter::class);
        $this->cache           = $this->createMock(TransientCacheAdapter::class);
        $this->logger          = $this->createMock(LogAdapter::class);
        $this->clock           = $this->createMock(SystemClockAdapter::class);
        $this->php_session     = $this->createMock(PhpSessionAdapter::class);
        $this->hasher          = $this->createMock(BcryptHasherAdapter::class);
        $this->session_manager = $this->createMock(SessionManagerAdapter::class);

        $this->service = new AuthService(
            $this->app,
            $this->session,
            $this->meta,
            $this->cache,
            $this->logger,
            $this->clock,
            $this->php_session,
            $this->hasher,
            $this->session_manager,
        );
    }

    // ── check_rate_limit ──────────────────────────────────────────────────────

    public function test_check_rate_limit_allows_first_attempt(): void
    {
        $this->app->method('get')->willReturnMap([
            ['IP', '1.2.3.4'],
            ['RATE_LIMIT', ['register' => [
                'ip'             => 5,
                'credential'     => 3,
                'ip_ttl'         => 3600,
                'credential_ttl' => 3600,
            ]]],
        ]);

        $this->cache->method('get')->willReturn(0);
        $this->cache->expects($this->exactly(2))->method('set');

        $result = $this->service->check_rate_limit(['email' => 'a@b.com'], 'register');

        $this->assertTrue($result);
    }

    public function test_check_rate_limit_blocks_when_ip_limit_reached(): void
    {
        $this->app->method('get')->willReturnMap([
            ['IP', '1.2.3.4'],
            ['RATE_LIMIT', ['register' => [
                'ip'             => 5,
                'credential'     => 3,
                'ip_ttl'         => 3600,
                'credential_ttl' => 3600,
            ]]],
        ]);

        $this->cache->method('get')->willReturn(5); // already at limit
        $this->cache->expects($this->never())->method('set');

        $result = $this->service->check_rate_limit(['email' => 'a@b.com'], 'register');

        $this->assertFalse($result);
    }

    public function test_check_rate_limit_blocks_when_credential_limit_reached(): void
    {
        $this->app->method('get')->willReturnMap([
            ['IP', '1.2.3.4'],
            ['RATE_LIMIT', ['register' => [
                'ip'             => 5,
                'credential'     => 3,
                'ip_ttl'         => 3600,
                'credential_ttl' => 3600,
            ]]],
        ]);

        // First call (ip key) returns 0, second call (cred key) returns 3 (at limit)
        $this->cache->method('get')->willReturnOnConsecutiveCalls(0, 3);
        $this->cache->expects($this->never())->method('set');

        $result = $this->service->check_rate_limit(['email' => 'a@b.com'], 'register');

        $this->assertFalse($result);
    }

    // ── login_with_secret ─────────────────────────────────────────────────────

    public function test_login_with_secret_returns_null_when_user_not_found(): void
    {
        $this->app->method('get')->with('IP')->willReturn('1.2.3.4');
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_credentials')->willReturn(null);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Auth login failed: user not found',
                $this->callback(function (array $context): bool {
                    return ($context['ip'] ?? null) === '1.2.3.4'
                        && ($context['credential_keys'] ?? null) === ['email']
                        && !array_key_exists('password', $context);
                })
            );

        $this->service->set_user_provider($provider);

        $result = $this->service->login_with_secret(['email' => 'a@b.com'], 'secret');

        $this->assertNull($result);
    }

    public function test_login_with_secret_returns_null_on_wrong_password(): void
    {
        $this->app->method('get')->with('IP')->willReturn('1.2.3.4');
        $user = $this->createMock(AuthenticatableInterface::class);
        $user->method('get_password_hash')->willReturn('$2y$...');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_credentials')->willReturn($user);

        $this->hasher->method('verify')->willReturn(false);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Auth login failed: invalid secret',
                $this->callback(function (array $context): bool {
                    return ($context['ip'] ?? null) === '1.2.3.4'
                        && ($context['credential_keys'] ?? null) === ['email']
                        && !array_key_exists('secret', $context);
                })
            );

        $this->service->set_user_provider($provider);

        $result = $this->service->login_with_secret(['email' => 'a@b.com'], 'wrong');

        $this->assertNull($result);
    }

    public function test_login_with_secret_starts_session_on_success(): void
    {
        $user_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $user = $this->createMock(AuthenticatableInterface::class);
        $user->method('get_password_hash')->willReturn('$2y$...');
        $user->method('get_auth_id')->willReturn($user_uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_credentials')->willReturn($user);

        $this->hasher->method('verify')->willReturn(true);

        $this->app->method('get')->willReturnMap([
            ['IP', '1.2.3.4'],
            ['AGENT', 'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess_abc123');
        $this->cache->method('get')->willReturn(0);

        $this->session->expects($this->once())->method('start')->with($user_uuid);
        $this->php_session->expects($this->once())->method('regenerate_id')->with(true);
        $this->meta->expects($this->once())->method('set_meta')
            ->with($user_uuid, 'auth_session_sess_abc123', $this->callback('is_string'));

        $this->service->set_user_provider($provider);
        $result = $this->service->login_with_secret(['email' => 'a@b.com'], 'correct');

        $this->assertSame($user, $result);
    }

    // ── logout ────────────────────────────────────────────────────────────────

    public function test_logout_does_nothing_when_no_session(): void
    {
        $this->session->method('is_started')->willReturn(false);
        $this->meta->expects($this->never())->method('delete_meta');

        $provider = $this->createMock(UserProviderInterface::class);
        $this->service->set_user_provider($provider);

        $this->service->logout();
    }

    public function test_logout_deletes_session_meta_and_destroys_session(): void
    {
        $user_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $user = $this->createMock(AuthenticatableInterface::class);
        $user->method('get_auth_id')->willReturn($user_uuid);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn($user_uuid);
        $this->php_session->method('id')->willReturn('sess_abc123');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->with($user_uuid)->willReturn($user);

        $this->meta->expects($this->once())
            ->method('delete_meta')
            ->with($user_uuid, 'auth_session_sess_abc123');

        $this->session->expects($this->once())->method('destroy');

        $this->service->set_user_provider($provider);
        $this->service->logout();
    }

    // ── is_impersonating ──────────────────────────────────────────────────────

    public function test_is_impersonating_returns_false_without_active_session(): void
    {
        $this->session->method('is_started')->willReturn(false);

        $this->assertFalse($this->service->is_impersonating());
    }

    public function test_is_impersonating_returns_true_when_admin_uuid_present(): void
    {
        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn('admin-uuid');

        $this->assertTrue($this->service->is_impersonating());
    }

    // ── kill_all_sessions ─────────────────────────────────────────────────────

    public function test_kill_all_sessions_skips_current_session_when_keep_current(): void
    {
        $user_id = 'user-uuid';
        $this->php_session->method('id')->willReturn('current_sid');

        $this->meta->method('get_meta_like')->with($user_id, 'auth_session_%')->willReturn([
            'auth_session_current_sid' => 'data1',
            'auth_session_other_sid'   => 'data2',
        ]);

        $this->meta->expects($this->once())
            ->method('delete_meta')
            ->with($user_id, 'auth_session_other_sid')
            ->willReturn(true);

        $this->session_manager->method('delete_session')->willReturn(true);
        $this->session_manager->method('get_driver')->willReturn('db');
        $this->logger->expects($this->once())->method('info');

        $count = $this->service->kill_all_sessions($user_id, true);

        $this->assertSame(1, $count);
    }

    public function test_kill_all_sessions_deletes_all_when_not_keeping_current(): void
    {
        $user_id = 'user-uuid';
        $this->php_session->method('id')->willReturn('current_sid');

        $this->meta->method('get_meta_like')->willReturn([
            'auth_session_current_sid' => 'data1',
            'auth_session_other_sid'   => 'data2',
        ]);
        $this->meta->expects($this->once())
            ->method('delete_meta_like')
            ->with($user_id, 'auth_session_%')
            ->willReturn(true);
        $this->session_manager->expects($this->once())
            ->method('delete_sessions')
            ->with(['current_sid', 'other_sid'])
            ->willReturn(2);
        $this->session_manager->method('get_driver')->willReturn('db');
        $this->logger->method('info');

        $count = $this->service->kill_all_sessions($user_id, false);

        $this->assertSame(2, $count);
    }

    public function test_kill_all_sessions_returns_zero_when_no_sessions_exist(): void
    {
        $user_id = 'user-uuid';
        $this->meta->method('get_meta_like')->willReturn([]);
        $this->session_manager->method('get_driver')->willReturn('db');
        $this->logger->method('info');

        $count = $this->service->kill_all_sessions($user_id, false);

        $this->assertSame(0, $count);
    }

    public function test_kill_all_sessions_does_not_count_when_both_deletes_fail(): void
    {
        $user_id = 'user-uuid';
        $this->php_session->method('id')->willReturn('current_sid');

        $this->meta->method('get_meta_like')->willReturn([
            'auth_session_other_sid' => 'data',
        ]);
        $this->meta->method('delete_meta')->willReturn(false);
        $this->session_manager->method('delete_session')->willReturn(false);
        $this->session_manager->method('get_driver')->willReturn('db');
        $this->logger->method('info');

        $count = $this->service->kill_all_sessions($user_id, true);

        $this->assertSame(0, $count);
    }

    // ── login_by_id ───────────────────────────────────────────────────────────

    public function test_login_by_id_starts_session_and_stores_meta(): void
    {
        $auth_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['IP',    '1.2.3.4'],
            ['AGENT', 'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess_xyz');

        $this->session->expects($this->once())->method('start')->with($auth_id);
        $this->php_session->expects($this->once())->method('regenerate_id')->with(true);
        $this->meta->expects($this->once())->method('set_meta')
            ->with($auth_id, 'auth_session_sess_xyz', $this->callback('is_string'));

        $this->service->login_by_id($auth_id);
    }

    public function test_login_by_id_merges_context_into_session_meta(): void
    {
        $auth_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->willReturnMap([
            ['IP',    '10.0.0.1'],
            ['AGENT', 'Bot/2.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('mobile');
        $this->clock->method('now')->willReturn(1_700_000_001);
        $this->php_session->method('id')->willReturn('sess_ctx');
        $this->session->method('start');
        $this->php_session->expects($this->once())->method('regenerate_id')->with(true);

        $this->meta->expects($this->once())->method('set_meta')
            ->with(
                $auth_id,
                'auth_session_sess_ctx',
                $this->callback(function (string $json): bool {
                    $data = json_decode($json, true);
                    return ($data['auth_provider'] ?? '') === 'google'
                        && ($data['ip'] ?? '') === '10.0.0.1';
                })
            );

        $this->service->login_by_id($auth_id, ['auth_provider' => 'google']);
    }

    // ── login_with_secret (additional) ────────────────────────────────────────

    public function test_login_with_secret_throws_when_no_provider(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->login_with_secret(['email' => 'a@b.com'], 'pass');
    }

    public function test_login_with_secret_returns_null_when_password_hash_is_null(): void
    {
        $user = $this->createMock(AuthenticatableInterface::class);
        $user->method('get_password_hash')->willReturn(null);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_credentials')->willReturn($user);

        $this->service->set_user_provider($provider);
        $result = $this->service->login_with_secret(['email' => 'a@b.com'], 'pass');

        $this->assertNull($result);
    }

    public function test_login_with_secret_clears_rate_limit_cache_on_success(): void
    {
        $user_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $user = $this->createMock(AuthenticatableInterface::class);
        $user->method('get_password_hash')->willReturn('$2y$...');
        $user->method('get_auth_id')->willReturn($user_uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_credentials')->willReturn($user);

        $this->hasher->method('verify')->willReturn(true);
        $this->app->method('get')->willReturnMap([
            ['IP',    '1.2.3.4'],
            ['AGENT', 'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess_abc');
        $this->session->method('start');

        $this->cache->expects($this->exactly(2))->method('delete');

        $this->service->set_user_provider($provider);
        $this->service->login_with_secret(['email' => 'a@b.com'], 'correct');
    }

    // ── logout (additional) ───────────────────────────────────────────────────

    public function test_logout_does_nothing_when_user_not_found_in_provider(): void
    {
        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn('orphan-uuid');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn(null);

        $this->meta->expects($this->never())->method('delete_meta');
        $this->session->expects($this->never())->method('destroy');

        $this->service->set_user_provider($provider);
        $this->service->logout();
    }

    // ── get_current_user ──────────────────────────────────────────────────────

    public function test_get_current_user_returns_null_when_session_not_started(): void
    {
        $this->session->method('is_started')->willReturn(false);

        $provider = $this->createMock(UserProviderInterface::class);
        $this->service->set_user_provider($provider);

        $this->assertNull($this->service->get_current_user());
    }

    public function test_get_current_user_throws_when_no_provider(): void
    {
        $this->session->method('is_started')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->service->get_current_user();
    }

    public function test_get_current_user_returns_null_when_no_uuid_in_session(): void
    {
        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn(null);

        $provider = $this->createMock(UserProviderInterface::class);
        $this->service->set_user_provider($provider);

        $this->assertNull($this->service->get_current_user());
    }

    public function test_get_current_user_returns_user_from_provider(): void
    {
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $user = $this->createMock(AuthenticatableInterface::class);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn($uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->with($uuid)->willReturn($user);
        $this->service->set_user_provider($provider);

        $this->assertSame($user, $this->service->get_current_user());
    }

    public function test_get_current_user_returns_null_when_provider_returns_null(): void
    {
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn($uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn(null);
        $this->service->set_user_provider($provider);

        $this->assertNull($this->service->get_current_user());
    }

    public function test_get_current_user_caches_result_and_does_not_re_query_provider(): void
    {
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $user = $this->createMock(AuthenticatableInterface::class);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.user_uuid')->willReturn($uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->once())->method('find_by_id')->willReturn($user);
        $this->service->set_user_provider($provider);

        $this->service->get_current_user(); // prime cache
        $result = $this->service->get_current_user(); // must use cached value

        $this->assertSame($user, $result);
    }

    // ── impersonate_user ──────────────────────────────────────────────────────

    public function test_impersonate_user_returns_false_when_not_logged_in(): void
    {
        $this->session->method('is_started')->willReturn(false);

        $provider = $this->createMock(UserProviderInterface::class);
        $this->service->set_user_provider($provider);

        $this->assertFalse($this->service->impersonate_user('target-uuid'));
    }

    public function test_impersonate_user_returns_false_when_current_user_is_not_admin(): void
    {
        $user_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $user = new class($user_uuid) implements AuthenticatableInterface, HasRolesInterface {
            public function __construct(private string $auth_id) {}

            public function get_auth_id(): string
            {
                return $this->auth_id;
            }

            public function get_password_hash(): ?string
            {
                return null;
            }

            public function get_role_slugs(): array
            {
                return [Role::SELLER->value];
            }
        };

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid', $user_uuid],
        ]);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->with($user_uuid)->willReturn($user);
        $this->service->set_user_provider($provider);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Impersonation denied: user lacks admin role', ['auth_id' => $user_uuid]);

        $this->app->expects($this->never())->method('set');
        $this->meta->expects($this->never())->method('set_meta');

        $this->assertFalse($this->service->impersonate_user('target-uuid'));
    }

    public function test_impersonate_user_sets_admin_uuid_stores_meta_returns_true(): void
    {
        $admin_uuid  = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $target_uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $admin = $this->role_user($admin_uuid, [Role::ADMIN->value]);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid',  $admin_uuid],
            ['SESSION.admin_uuid', null],
            ['IP',                 '1.2.3.4'],
            ['AGENT',              'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('new_sess');
        $this->logger->method('info');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn($admin);
        $this->service->set_user_provider($provider);

        $set_calls = [];
        $this->app->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$set_calls): void { $set_calls[$k] = $v; }
        );
        $this->meta->expects($this->once())->method('set_meta')
            ->with($target_uuid, 'auth_session_new_sess', $this->callback('is_string'));

        $result = $this->service->impersonate_user($target_uuid);

        $this->assertTrue($result);
        $this->assertSame($admin_uuid, $set_calls['SESSION.admin_uuid'] ?? null);
        $this->assertSame($target_uuid, $set_calls['SESSION.user_uuid'] ?? null);
    }

    public function test_impersonate_user_does_not_overwrite_existing_admin_uuid(): void
    {
        $admin_uuid     = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $original_admin = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $target_uuid    = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $admin = $this->role_user($admin_uuid, [Role::ADMIN->value]);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid',  $admin_uuid],
            ['SESSION.admin_uuid', $original_admin],
            ['IP',                 '1.2.3.4'],
            ['AGENT',              'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess');
        $this->meta->method('set_meta');
        $this->logger->method('info');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn($admin);
        $this->service->set_user_provider($provider);

        $set_calls = [];
        $this->app->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$set_calls): void { $set_calls[$k] = $v; }
        );

        $this->service->impersonate_user($target_uuid);

        $this->assertArrayNotHasKey('SESSION.admin_uuid', $set_calls);
        $this->assertSame($target_uuid, $set_calls['SESSION.user_uuid'] ?? null);
    }

    public function test_impersonate_user_regenerates_session_id(): void
    {
        $admin_uuid  = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $target_uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $admin = $this->role_user($admin_uuid, [Role::ADMIN->value]);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->willReturnMap([
            ['SESSION.user_uuid',  $admin_uuid],
            ['SESSION.admin_uuid', null],
            ['IP',                 '1.2.3.4'],
            ['AGENT',              'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->app->method('set');
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess');
        $this->meta->method('set_meta');
        $this->logger->method('info');

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn($admin);
        $this->service->set_user_provider($provider);

        $this->php_session->expects($this->once())->method('regenerate_id')->with(true);

        $this->service->impersonate_user($target_uuid);
    }

    // ── stop_impersonation ────────────────────────────────────────────────────

    public function test_stop_impersonation_returns_false_when_not_impersonating(): void
    {
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn(null);

        $this->assertFalse($this->service->stop_impersonation());
    }

    public function test_stop_impersonation_restores_admin_uuid_clears_impersonation_and_stores_meta(): void
    {
        $admin_uuid      = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $impersonated_id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $this->app->method('get')->willReturnMap([
            ['SESSION.admin_uuid', $admin_uuid],
            ['SESSION.user_uuid',  $impersonated_id],
            ['IP',                 '1.2.3.4'],
            ['AGENT',              'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->session->method('is_started')->willReturn(true);
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('restored_sess');
        $this->logger->method('info');

        $set_calls = [];
        $this->app->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$set_calls): void { $set_calls[$k] = $v; }
        );

        $this->php_session->expects($this->once())->method('regenerate_id')->with(true);
        $this->meta->expects($this->once())->method('set_meta')
            ->with($admin_uuid, 'auth_session_restored_sess', $this->callback('is_string'));

        $result = $this->service->stop_impersonation();

        $this->assertTrue($result);
        $this->assertSame($admin_uuid, $set_calls['SESSION.user_uuid'] ?? null);
        $this->assertArrayHasKey('SESSION.admin_uuid', $set_calls);
        $this->assertNull($set_calls['SESSION.admin_uuid']);
    }

    public function test_stop_impersonation_skips_regenerate_when_session_not_started(): void
    {
        $admin_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';

        $this->app->method('get')->willReturnMap([
            ['SESSION.admin_uuid', $admin_uuid],
            ['SESSION.user_uuid',  'bbbb-uuid'],
            ['IP',                 '1.2.3.4'],
            ['AGENT',              'TestAgent/1.0'],
        ]);
        $this->app->method('get_device_type')->willReturn('desktop');
        $this->app->method('set');
        $this->session->method('is_started')->willReturn(false);
        $this->clock->method('now')->willReturn(1_700_000_000);
        $this->php_session->method('id')->willReturn('sess');
        $this->meta->method('set_meta');
        $this->logger->method('info');

        $this->php_session->expects($this->never())->method('regenerate_id');

        $result = $this->service->stop_impersonation();
        $this->assertTrue($result);
    }

    // ── is_impersonating (additional) ─────────────────────────────────────────

    public function test_is_impersonating_returns_false_when_session_active_but_no_admin_uuid(): void
    {
        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn(null);

        $this->assertFalse($this->service->is_impersonating());
    }

    // ── get_real_admin ────────────────────────────────────────────────────────

    public function test_get_real_admin_returns_null_when_not_impersonating(): void
    {
        $this->session->method('is_started')->willReturn(false);

        $provider = $this->createMock(UserProviderInterface::class);
        $this->service->set_user_provider($provider);

        $this->assertNull($this->service->get_real_admin());
    }

    public function test_get_real_admin_throws_when_no_provider(): void
    {
        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn('admin-uuid');

        $this->expectException(\RuntimeException::class);
        $this->service->get_real_admin();
    }

    public function test_get_real_admin_returns_admin_user_from_provider(): void
    {
        $admin_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';
        $admin      = $this->createMock(AuthenticatableInterface::class);

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn($admin_uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->with($admin_uuid)->willReturn($admin);
        $this->service->set_user_provider($provider);

        $this->assertSame($admin, $this->service->get_real_admin());
    }

    public function test_get_real_admin_returns_null_when_provider_returns_null(): void
    {
        $admin_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-aaaaaaaaaaaa';

        $this->session->method('is_started')->willReturn(true);
        $this->app->method('get')->with('SESSION.admin_uuid')->willReturn($admin_uuid);

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn(null);
        $this->service->set_user_provider($provider);

        $this->assertNull($this->service->get_real_admin());
    }

    private function role_user(string $auth_id, array $roles): AuthenticatableInterface&HasRolesInterface
    {
        return new class($auth_id, $roles) implements AuthenticatableInterface, HasRolesInterface {
            public function __construct(
                private string $auth_id,
                private array $roles,
            ) {}

            public function get_auth_id(): string
            {
                return $this->auth_id;
            }

            public function get_password_hash(): ?string
            {
                return null;
            }

            public function get_role_slugs(): array
            {
                return $this->roles;
            }
        };
    }

    private function isString(mixed $value): bool
    {
        return is_string($value);
    }
}
