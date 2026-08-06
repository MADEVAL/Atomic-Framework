<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\TelegramClientAdapter;
use Engine\Atomic\Auth\Interfaces\LoginInterface;
use Engine\Atomic\Auth\Interfaces\OAuthUserResolverInterface;
use Engine\Atomic\Auth\Services\TelegramAuthService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TelegramAuthServiceTest extends TestCase
{
    private AppContextAdapter&MockObject     $app;
    private TelegramClientAdapter&MockObject $telegram_client;
    private LogAdapter&MockObject            $logger;
    private IdValidatorAdapter&MockObject    $id_validator;
    private LoginInterface&MockObject        $auth;
    private TelegramAuthService              $service;

    protected function setUp(): void
    {
        $this->app             = $this->createMock(AppContextAdapter::class);
        $this->telegram_client = $this->createMock(TelegramClientAdapter::class);
        $this->logger          = $this->createMock(LogAdapter::class);
        $this->id_validator    = $this->createMock(IdValidatorAdapter::class);
        $this->auth            = $this->createMock(LoginInterface::class);

        $this->service = new TelegramAuthService(
            $this->app,
            $this->telegram_client,
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

    // ── get_bot_username ──────────────────────────────────────────────────────

    public function test_get_bot_username_returns_empty_string_when_not_configured(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([]);

        $this->assertSame('', $this->service->get_bot_username());
    }

    public function test_get_bot_username_returns_configured_value(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([
            'bot_username' => 'my_awesome_bot',
        ]);

        $this->assertSame('my_awesome_bot', $this->service->get_bot_username());
    }

    // ── get_callback_url ──────────────────────────────────────────────────────

    public function test_get_callback_url_returns_default_when_not_configured(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([]);

        $this->assertSame('/auth/telegram/callback', $this->service->get_callback_url());
    }

    public function test_get_callback_url_returns_configured_value(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([
            'callback_url' => '/my/custom/tg/callback',
        ]);

        $this->assertSame('/my/custom/tg/callback', $this->service->get_callback_url());
    }

    // ── is_configured ─────────────────────────────────────────────────────────

    public function test_is_configured_returns_false_when_config_is_empty(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_false_when_bot_token_is_missing(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([
            'bot_username' => 'my_bot',
        ]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_false_when_bot_username_is_missing(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([
            'bot_token' => 'secret_token',
        ]);

        $this->assertFalse($this->service->is_configured());
    }

    public function test_is_configured_returns_true_when_both_fields_are_present(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn([
            'bot_username' => 'my_bot',
            'bot_token'    => 'secret_token',
        ]);

        $this->assertTrue($this->service->is_configured());
    }

    // ── verify_auth_data ──────────────────────────────────────────────────────

    public function test_verify_auth_data_delegates_to_client_and_returns_verified_data(): void
    {
        $auth_data = ['id' => '123', 'hash' => 'abc'];
        $verified  = ['id' => '123', 'first_name' => 'Test'];

        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')
            ->with($auth_data, 'tok')
            ->willReturn($verified);

        $this->assertSame($verified, $this->service->verify_auth_data($auth_data));
    }

    public function test_verify_auth_data_returns_false_when_verification_fails(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')->willReturn(false);

        $this->assertFalse($this->service->verify_auth_data(['id' => 'bad']));
    }

    // ── handle_callback ───────────────────────────────────────────────────────

    public function test_handle_callback_throws_when_resolver_not_configured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->handle_callback(['id' => '123']);
    }

    public function test_handle_callback_returns_null_when_verification_fails(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')->willReturn(false);
        $this->logger->expects($this->once())->method('warning');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback(['id' => '123']));
    }

    public function test_handle_callback_returns_null_when_resolver_returns_null(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')->willReturn($this->valid_telegram_data());
        $this->logger->expects($this->once())->method('error');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn(null);
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback(['id' => '123']));
    }

    public function test_handle_callback_returns_null_when_resolver_returns_invalid_uuid(): void
    {
        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')->willReturn($this->valid_telegram_data());
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(false);
        $this->logger->expects($this->once())->method('error');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn('not-a-uuid');
        $this->service->set_user_resolver($resolver);

        $this->assertNull($this->service->handle_callback(['id' => '123']));
    }

    public function test_handle_callback_calls_login_by_id_and_returns_identifier_on_success(): void
    {
        $user_id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->app->method('get')->with('OAUTH.telegram')->willReturn(['bot_token' => 'tok']);
        $this->telegram_client->method('verify_login_widget')->willReturn($this->valid_telegram_data());
        $this->id_validator->method('is_valid_uuid_v4')->willReturn(true);
        $this->logger->method('info');

        $resolver = $this->createMock(OAuthUserResolverInterface::class);
        $resolver->method('resolve_oauth_user')->willReturn($user_id);
        $this->service->set_user_resolver($resolver);

        $this->auth->expects($this->once())->method('login_by_id')
            ->with($user_id, $this->arrayHasKey('auth_provider'));

        $result = $this->service->handle_callback(['id' => '123', 'hash' => 'abc']);

        $this->assertSame($user_id, $result);
    }

    // ── get_widget_attributes ─────────────────────────────────────────────────

    public function test_get_widget_attributes_delegates_to_telegram_client(): void
    {
        $expected = ['data-telegram-login' => 'my_bot', 'data-size' => 'large'];
        $this->telegram_client->method('get_widget_attributes')->willReturn($expected);

        $result = $this->service->get_widget_attributes('large', false, false, 20);

        $this->assertSame($expected, $result);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function valid_telegram_data(): array
    {
        return [
            'id'         => 12345,
            'first_name' => 'Test',
            'last_name'  => 'User',
            'username'   => 'testuser',
            'photo_url'  => 'https://t.me/pic.jpg',
        ];
    }
}
