<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Exceptions\TelegramException;

/**
 * Bot API calls return Telegram's decoded answer (`['ok' => true, 'result' => ...]`)
 * and throw TelegramException on any failure. verify_* methods return false instead.
 */
final class Telegram
{
    use Singleton;

    public const LOGIN_DEFAULT_MAX_AGE = 300;
    public const LOGIN_MAX_AGE_LIMIT = 86400;
    public const LOGIN_CLOCK_SKEW = 60;

    protected App $atomic;

    protected string $token = '';
    protected string|int|null $chat_id = null;

    private function __construct(?string $token = null, string|int|null $chat_id = null)
    {
        $this->atomic = App::instance();
        $this->token = $token ?: (string)$this->atomic->get('TELEGRAM_BOT_TOKEN') ?: '';
        $this->chat_id = $chat_id ?: $this->atomic->get('TELEGRAM_CHAT_ID') ?: null;
    }

    public static function for(?string $token = null, string|int|null $chat_id = null): self
    {
        return new self($token, $chat_id);
    }

    public function set_token(string $token): self
    {
        $this->token = trim($token);
        return $this;
    }

    public function set_chat_id(string|int $chat_id): self
    {
        $this->chat_id = $chat_id;
        return $this;
    }

    protected function api(string $method, array $params = []): array
    {
        if ($this->token === '') {
            throw new TelegramException('Telegram bot token is empty', $method);
        }

        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;

        $hasFile = (bool)array_filter($params, fn($v) => $v instanceof \CURLFile);
        if ($hasFile) {
            $response = Request::instance()->remote_post($url, null, [
                'raw'     => true,
                'content' => $params,
            ]);
        } else {
            $response = Request::instance()->remote_post($url, $params, [
                'headers' => ['Content-Type' => 'application/json'],
                'raw'     => true,
                'content' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        // Transport errors can echo the request URL, which contains the token.
        $response['error'] = str_replace($this->token, '[token]', (string)($response['error'] ?? ''));

        return self::parse_response($method, $response);
    }

    /**
     * @param array{ok: bool, status: int, error: string, body: string} $response Request::remote_post() result
     * @return array Telegram's answer with `ok === true`
     * @throws TelegramException
     */
    private static function parse_response(string $method, array $response): array
    {
        $status = (int)($response['status'] ?? 0);
        $json = json_decode((string)($response['body'] ?? ''), true);

        if (is_array($json) && ($json['ok'] ?? false) === true && !empty($response['ok'])) {
            return $json;
        }

        if (is_array($json) && array_key_exists('ok', $json)) {
            $code = isset($json['error_code']) ? (int)$json['error_code'] : ($status ?: null);
            $description = (string)($json['description'] ?? 'unknown error');
            throw new TelegramException("Telegram {$method} failed: {$description}", $method, $code);
        }

        if (empty($response['ok'])) {
            $reason = $response['error'] !== '' ? $response['error'] : 'HTTP ' . $status;
            throw new TelegramException("Telegram {$method} request failed: {$reason}", $method, $status ?: null);
        }

        throw new TelegramException("Telegram {$method} returned an invalid response (HTTP {$status})", $method, $status ?: null);
    }

    private function require_chat_id(string|int|null $chat_id, string $method): string|int
    {
        if ($chat_id === null || $chat_id === '') {
            throw new TelegramException("Telegram {$method}: chat_id is empty", $method);
        }
        return $chat_id;
    }

    public function send(string $text, ?int $chat_id = null, array $opts = []): array
    {
        $chat = $this->require_chat_id($chat_id ?? $this->chat_id, 'sendMessage');

        $params = ['chat_id' => $chat, 'text' => $text] + $opts;
        return $this->api('sendMessage', $params);
    }

    public function send_photo(string $photo, array $opts = []): array
    {
        $chat_id = $this->require_chat_id($opts['chat_id'] ?? $this->chat_id, 'sendPhoto');
        $params = ['chat_id' => $chat_id];
        if (is_file($photo) && is_readable($photo)) {
            $params['photo'] = new \CURLFile($photo, mime_content_type($photo) ?: 'application/octet-stream', basename($photo));
        } else {
            $params['photo'] = $photo;
        }
        if (isset($opts['caption'])) $params['caption'] = $opts['caption'];
        if (isset($opts['parse_mode'])) $params['parse_mode'] = $opts['parse_mode'];
        if (isset($opts['disable_notification'])) $params['disable_notification'] = (int)!empty($opts['disable_notification']);
        if (isset($opts['reply_to_message_id'])) $params['reply_to_message_id'] = (int)$opts['reply_to_message_id'];
        if (isset($opts['reply_markup'])) $params['reply_markup'] = is_string($opts['reply_markup']) 
            ? $opts['reply_markup'] 
            : json_encode($opts['reply_markup'], JSON_UNESCAPED_UNICODE);
        return $this->api('sendPhoto', $params);
    }

    public function send_document(string $document, array $opts = []): array
    {
        $chat_id = $this->require_chat_id($opts['chat_id'] ?? $this->chat_id, 'sendDocument');
        $params = ['chat_id' => $chat_id];
        if (is_file($document) && is_readable($document)) {
            $params['document'] = new \CURLFile($document, mime_content_type($document) ?: 'application/octet-stream', basename($document));
        } else {
            $params['document'] = $document;
        }
        if (isset($opts['caption'])) $params['caption'] = $opts['caption'];
        if (isset($opts['parse_mode'])) $params['parse_mode'] = $opts['parse_mode'];
        if (isset($opts['disable_notification'])) $params['disable_notification'] = (int)!empty($opts['disable_notification']);
        if (isset($opts['reply_to_message_id'])) $params['reply_to_message_id'] = (int)$opts['reply_to_message_id'];
        if (isset($opts['reply_markup'])) $params['reply_markup'] = is_string($opts['reply_markup']) 
            ? $opts['reply_markup'] 
            : json_encode($opts['reply_markup'], JSON_UNESCAPED_UNICODE);
        return $this->api('sendDocument', $params);
    }

    public function get_me(): array
    {
        return $this->api('getMe');
    }

    public function create_invoice_link(array $data): ?string
    {
        if (isset($data['prices']) && !is_string($data['prices'])) {
            $data['prices'] = json_encode($data['prices'], JSON_UNESCAPED_UNICODE);
        }
        $response = $this->api('createInvoiceLink', $data);
        return isset($response['result']) ? (string)$response['result'] : null;
    }

    public function send_invoice(array $data): array
    {
        $data['chat_id'] = $this->require_chat_id($data['chat_id'] ?? $this->chat_id, 'sendInvoice');
        return $this->api('sendInvoice', $data);
    }

    public function verify_web_app_init_data(string|array $init_data): array|false
    {
        $data = is_array($init_data) ? $init_data : (function ($s) { parse_str($s, $out); return $out; })($init_data);
        if (!is_array($data) || empty($data['hash']) || $this->token === '') return false;
        $hash = $data['hash'];
        unset($data['hash']);
        ksort($data);
        $check = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $check[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $check);
        $secretKey = hash_hmac('sha256', $this->token, 'WebAppData', true);
        $calc = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));
        if (!hash_equals($hash, $calc)) return false;
        return $data;
    }

    /**
     * @param int $max_age Seconds signed login data stays valid; must be positive.
     *                     auth_date further than LOGIN_CLOCK_SKEW seconds in the future is rejected.
     */
    public function verify_login_widget(array $auth_data, ?string $bot_token = null, int $max_age = self::LOGIN_DEFAULT_MAX_AGE): array|false
    {
        if ($max_age <= 0) {
            Log::error('[Telegram] verify_login_widget: max age must be positive');
            return false;
        }

        $token = $bot_token ?: $this->token;
        if (empty($token)) {
            Log::error('[Telegram] Bot token is empty for verify_login_widget');
            return false;
        }

        if (empty($auth_data['hash']) || empty($auth_data['id']) || empty($auth_data['auth_date'])) {
            Log::error('[Telegram] verify_login_widget missing required fields');
            return false;
        }

        $age = time() - (int)$auth_data['auth_date'];
        if ($age > $max_age) {
            Log::error('[Telegram] verify_login_widget: auth data is outdated');
            return false;
        }
        if ($age < -self::LOGIN_CLOCK_SKEW) {
            Log::error('[Telegram] verify_login_widget: auth_date is in the future');
            return false;
        }

        $check_hash = $auth_data['hash'];
        unset($auth_data['hash']);

        ksort($auth_data);
        $data_check_parts = [];
        foreach ($auth_data as $k => $v) {
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data_check_parts[] = $k . '=' . $v;
        }
        $data_check_string = implode("\n", $data_check_parts);

        $secret_key = hash('sha256', $token, true);
        $hash_hex = hash_hmac('sha256', $data_check_string, $secret_key);

        if (hash_equals($hash_hex, $check_hash)) {
            $auth_data['hash'] = $check_hash;
            return $auth_data;
        }

        Log::error('[Telegram] verify_login_widget: hash mismatch');
        return false;
    }

    public function get_widget_attributes(string $size = 'large', bool $request_access = false, bool $use_avatar = false, int $corner_radius = 20): array
    {
        $cfg = App::instance()->get('OAUTH.telegram') ?: [];
        return [
            'telegram-login' => $cfg['bot_username'] ?? '',
            'size' => $size,
            'auth-url' => $cfg['callback_url'] ?? '/auth/telegram/callback',
            'userpic' => $use_avatar ? 'true' : 'false',
            'radius' => $corner_radius,
            'request-access' => $request_access ? 'write' : null,
            'bot_id' => explode(':', $cfg['bot_token'])[0] ?? ''
        ];
    }

    public function answer_pre_checkout_query(string $pre_checkout_query_id, bool $ok, string $error_message = ''): array
    {
        $packet = ['pre_checkout_query_id' => $pre_checkout_query_id, 'ok' => $ok];
        if (!$ok && $error_message !== '') $packet['error_message'] = $error_message;
        return $this->api('answerPreCheckoutQuery', $packet);
    }

    public function answer_shipping_query(string $shipping_query_id, bool $ok, array $shipping_options = [], string $error_message = ''): array
    {
        $packet = ['shipping_query_id' => $shipping_query_id, 'ok' => $ok];
        if ($ok) $packet['shipping_options'] = json_encode($shipping_options, JSON_UNESCAPED_UNICODE);
        if (!$ok && $error_message !== '') $packet['error_message'] = $error_message;
        return $this->api('answerShippingQuery', $packet);
    }

    public function set_webhook(string $url, array $opts = []): array
    {
        $packet = ['url' => $url] + $opts;
        return $this->api('setWebhook', $packet);
    }

    public function delete_webhook(bool $drop_pending_updates = false): array
    {
        return $this->api('deleteWebhook', ['drop_pending_updates' => $drop_pending_updates]);
    }

    public function set_chat_menu_button(string $text, string $url, string $type = 'web_app'): array
    {
        return $this->api('setChatMenuButton', [
            'menu_button' => [
                'type' => $type,
                'text' => $text,
                'web_app' => ['url' => $url],
            ],
        ]);
    }

    public function delete_chat_menu_button(): array
    {
        return $this->api('setChatMenuButton', [
            'menu_button' => [
                'type' => 'default',
            ],
        ]);
    }

    public function set_my_description(string $description, string $language_code = ''): array
    {
        $params = ['description' => $description];
        if ($language_code !== '') {
            $params['language_code'] = $language_code;
        }
        return $this->api('setMyDescription', $params);
    }

    public function set_my_short_description(string $short_description, string $language_code = ''): array
    {
        $params = ['short_description' => $short_description];
        if ($language_code !== '') {
            $params['language_code'] = $language_code;
        }
        return $this->api('setMyShortDescription', $params);
    }

    public function set_my_name(string $name, string $language_code = ''): array
    {
        $params = ['name' => $name];
        if ($language_code !== '') {
            $params['language_code'] = $language_code;
        }
        return $this->api('setMyName', $params);
    }
}