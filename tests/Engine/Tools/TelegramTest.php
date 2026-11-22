<?php
declare(strict_types=1);

namespace Tests\Engine\Tools;

use Engine\Atomic\Exceptions\AtomicException;
use Engine\Atomic\Exceptions\TelegramException;
use Engine\Atomic\Tools\Telegram;
use PHPUnit\Framework\TestCase;

class TelegramTest extends TestCase
{
    protected function tearDown(): void
    {
        Telegram::reset();
    }

    public function test_instance_uses_the_core_singleton(): void
    {
        $first = Telegram::instance('123:abc');

        $this->assertInstanceOf(Telegram::class, $first);
        $this->assertSame($first, Telegram::instance());
    }

    public function test_call_without_token_throws_telegram_exception(): void
    {
        try {
            Telegram::for('')->set_token('')->get_me();
            $this->fail('Expected TelegramException');
        } catch (TelegramException $e) {
            $this->assertInstanceOf(AtomicException::class, $e);
            $this->assertSame('getMe', $e->get_api_method());
            $this->assertNull($e->get_error_code());
        }
    }

    public function test_send_without_chat_id_throws_before_calling_the_api(): void
    {
        $this->expectException(TelegramException::class);
        $this->expectExceptionMessage('chat_id');

        Telegram::for('123:abc')->send('hi');
    }

    public function test_api_error_answer_throws_with_telegram_error_code(): void
    {
        try {
            $this->parse('setWebhook', [
                'ok' => false,
                'status' => 401,
                'error' => '',
                'body' => '{"ok":false,"error_code":401,"description":"Unauthorized"}',
            ]);
            $this->fail('Expected TelegramException');
        } catch (TelegramException $e) {
            $this->assertSame('setWebhook', $e->get_api_method());
            $this->assertSame(401, $e->get_error_code());
            $this->assertSame(401, $e->getCode());
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }

    public function test_ok_false_with_http_200_throws(): void
    {
        $this->expectException(TelegramException::class);

        $this->parse('getMe', ['ok' => true, 'status' => 200, 'error' => '', 'body' => '{"ok":false,"error_code":400,"description":"Bad"}']);
    }

    public function test_transport_failure_throws_without_error_code(): void
    {
        try {
            $this->parse('getMe', ['ok' => false, 'status' => 0, 'error' => 'Operation timed out', 'body' => '']);
            $this->fail('Expected TelegramException');
        } catch (TelegramException $e) {
            $this->assertNull($e->get_error_code());
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
        }
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(TelegramException::class);

        $this->parse('getMe', ['ok' => true, 'status' => 200, 'error' => '', 'body' => '<html>']);
    }

    public function test_successful_answer_is_returned(): void
    {
        $result = $this->parse('getMe', ['ok' => true, 'status' => 200, 'error' => '', 'body' => '{"ok":true,"result":{"id":1}}']);

        $this->assertSame(['ok' => true, 'result' => ['id' => 1]], $result);
    }

    // ── verify_login_widget ───────────────────────────────────────────────────

    public function test_verify_login_widget_accepts_fresh_data(): void
    {
        $data = $this->signed_login(time() - 10);

        $this->assertSame($data, Telegram::for('123:tok')->verify_login_widget($data, '123:tok'));
    }

    public function test_verify_login_widget_rejects_data_older_than_default_max_age(): void
    {
        $data = $this->signed_login(time() - Telegram::LOGIN_DEFAULT_MAX_AGE - 5);

        $this->assertFalse(Telegram::for('123:tok')->verify_login_widget($data, '123:tok'));
    }

    public function test_verify_login_widget_honours_custom_max_age(): void
    {
        $data = $this->signed_login(time() - 600);

        $this->assertFalse(Telegram::for('123:tok')->verify_login_widget($data, '123:tok', 300));
        $this->assertSame($data, Telegram::for('123:tok')->verify_login_widget($data, '123:tok', 900));
    }

    public function test_verify_login_widget_rejects_auth_date_in_the_future(): void
    {
        $data = $this->signed_login(time() + Telegram::LOGIN_CLOCK_SKEW + 60);

        $this->assertFalse(Telegram::for('123:tok')->verify_login_widget($data, '123:tok'));
    }

    public function test_verify_login_widget_tolerates_small_clock_skew(): void
    {
        $data = $this->signed_login(time() + 5);

        $this->assertSame($data, Telegram::for('123:tok')->verify_login_widget($data, '123:tok'));
    }

    public function test_verify_login_widget_rejects_non_positive_max_age(): void
    {
        $data = $this->signed_login(time());

        $this->assertFalse(Telegram::for('123:tok')->verify_login_widget($data, '123:tok', 0));
    }

    public function test_verify_login_widget_rejects_tampered_data(): void
    {
        $data = $this->signed_login(time());
        $data['id'] = '999';

        $this->assertFalse(Telegram::for('123:tok')->verify_login_widget($data, '123:tok'));
    }

    private function signed_login(int $auth_date, string $token = '123:tok'): array
    {
        $data = ['auth_date' => (string) $auth_date, 'first_name' => 'Test', 'id' => '42', 'username' => 'tester'];
        ksort($data);
        $parts = [];
        foreach ($data as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $data['hash'] = hash_hmac('sha256', implode("\n", $parts), hash('sha256', $token, true));

        return $data;
    }

    private function parse(string $method, array $response): array
    {
        $parse = new \ReflectionMethod(Telegram::class, 'parse_response');

        return $parse->invoke(null, $method, $response);
    }
}
