<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\GoogleClientAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;
use Engine\Atomic\Auth\Services\GoogleAuthService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class GoogleAuthServiceTest extends TestCase
{
    private AppContextAdapter&MockObject   $app;
    private GoogleClientAdapter&MockObject $google_client;
    private LogAdapter&MockObject          $logger;
    private IdValidatorAdapter&MockObject  $id_validator;
    private LoginInterface&MockObject      $auth;
    private GoogleAuthService              $service;

    protected function setUp(): void
    {
        $this->app           = $this->createMock(AppContextAdapter::class);
        $this->google_client = $this->createMock(GoogleClientAdapter::class);
        $this->logger        = $this->createMock(LogAdapter::class);
        $this->id_validator  = $this->createMock(IdValidatorAdapter::class);
        $this->auth          = $this->createMock(LoginInterface::class);

        $this->service = new GoogleAuthService(
            $this->app,
            $this->google_client,
            $this->logger,
            $this->id_validator,
            $this->auth,
        );
    }

    // ── set_user_resolver ─────────────────────────────────────────────────────

    public function test_set_user_resolver_returns_self(): void
    {
        $resolver = $this->createMock(OAuthUserResolverInterface::class);

        $this->assertSame($this->service, $this->service->set_user_resolver($resolver));
    }

    // ── get_login_url ───────────────────────────────────────────────────────────

    public function test_get_login_url_delegates_to_google_client(): void
    {
        $captured_state = null;
        $this->app->expects($this->once())
            ->method('set')
            ->with(
                'SESSION.oauth_google_state',
                $this->callback(function (string $state) use (&$captured_state): bool {
                    $captured_state = $state;
                    return strlen($state) === 32;
                })
            );
        $this->google_client->expects($this->once())
            ->method('create_auth_url')
            ->with($this->callback(function (string $state) use (&$captured_state): bool {
                return $state === $captured_state;
            }))
            ->willReturnCallback(fn (string $state): string => 'https://accounts.google.com/o/oauth2/auth?state=' . $state);

        $url = $this->service->get_login_url();

        $this->assertStringContainsString((string) $captured_state, $url);
    }

    // ── handle_callback ────────────────────────────────────────────────────────

    public function test_handle_callback_throws_when_resolver_not_configured(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');

        $this->expectException(\RuntimeException::class);
        $this->service->handle_callback('some-code', 'known-state');
    }

    public function test_handle_callback_returns_null_when_state_is_missing(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->logger->expects($this->once())->method('warning')->with('[GoogleAuth] Invalid OAuth state');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback('some-code'));
    }

    public function test_handle_callback_returns_null_when_state_mismatches(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->logger->expects($this->once())->method('warning')->with('[GoogleAuth] Invalid OAuth state');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback('some-code', 'wrong-state'));
    }

    public function test_handle_callback_returns_null_when_token_exchange_fails(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->google_client->method('fetch_user_info_by_code')->willReturn(null);
        $this->logger->expects($this->once())->method('warning');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback('bad-code', 'known-state'));
    }

    public function test_handle_callback_returns_null_when_resolver_returns_null(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->google_client->method('fetch_user_info_by_code')->willReturn($this->valid_google_info());
        $this->logger->expects($this->once())->method('error');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn(null);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback('code', 'known-state'));
    }

    public function test_handle_callback_returns_null_when_resolver_returns_invalid_uuid(): void
    {
        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->google_client->method('fetch_user_info_by_code')->willReturn($this->valid_google_info());
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(false);
        $this->logger->expects($this->once())->method('error');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn('not-a-uuid');
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback('code', 'known-state'));
    }

    public function test_handle_callback_calls_login_by_id_and_returns_identifier_on_success(): void
    {
        $user_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->with('SESSION.oauth_google_state')->willReturn('known-state');
        $this->app->expects($this->once())->method('clear')->with('SESSION.oauth_google_state');
        $this->google_client->method('fetch_user_info_by_code')->willReturn($this->valid_google_info());
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->logger->method('info');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn($user_id);
        $this->service->set_user_resolver($resolver);

        $this->auth->expects($this->once())->method('login_by_id')
            ->with($user_id, $this->arrayHasKey('auth_provider'));

        $result = $this->service->handle_callback('valid-code', 'known-state');

        $this->assertSame($user_id, $result);
    }

    // ── is_configured ─────────────────────────────────────────────────────────

    public function test_is_configured_returns_false_when_config_is_empty(): void
    {
        $this->app->method('get')->with('OAUTH.google')->willReturn([]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_false_when_client_secret_is_missing(): void
    {
        $this->app->method('get')->with('OAUTH.google')->willReturn([
            'client_id'    => 'id',
            'redirect_uri' => 'https://example.com/callback',
        ]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_false_when_redirect_uri_is_missing(): void
    {
        $this->app->method('get')->with('OAUTH.google')->willReturn([
            'client_id'     => 'id',
            'client_secret' => 'secret',
        ]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_true_when_all_required_fields_present(): void
    {
        $this->app->method('get')->with('OAUTH.google')->willReturn([
            'client_id'     => 'id',
            'client_secret' => 'secret',
            'redirect_uri'  => 'https://example.com/callback',
        ]);

        $this->assertTrue($this->service->is_configured());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function valid_google_info(): array
    {
        return [
            'id'          => '12345678',
            'email'       => 'user@example.com',
            'name'        => 'Test User',
            'given_name'  => 'Test',
            'family_name' => 'User',
            'picture'     => 'https://example.com/pic.jpg',
        ];
    }
}
