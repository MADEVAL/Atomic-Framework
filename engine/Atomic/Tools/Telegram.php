<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Request;

final class Telegram
{
    protected App $atomic;
    private static ?self $instance = null;

    protected string $token = '';
    protected string|int|null $chat_id = null;

    private function __construct(?string $token = null, string|int|null $chat_id = null)
    {
        $this->atomic = App::instance();
        $this->token = $token ?: (string)($this->atomic->get('TELEGRAM_BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '');
        $this->chat_id = $chat_id ?: ($this->atomic->get('TELEGRAM_CHAT_ID') ?: getenv('TELEGRAM_CHAT_ID') ?: null);
    }

    public static function instance(?string $token = null, string|int|null $chat_id = null): self
    {
        if ($token !== null || $chat_id !== null) {
            return new self($token, $chat_id);
        }
        return self::$instance ??= new self();
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
            Log::error('[Telegram] Token is empty');
            return ['ok' => false, 'error' => 'empty_token'];
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

        if (!$response['ok']) {
            $errJson = json_decode($response['body'], true);
            if (is_array($errJson)) {
                Log::error('[Telegram] API error: ' . json_encode(['method' => $method, 'response' => $errJson], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $errJson;
            }
            Log::error('[Telegram] Request failed: ' . json_encode(['method' => $method, 'error' => $response['error'], 'status' => $response['status']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => false, 'error' => $response['error'] ?: ('http_' . $response['status'])];
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            Log::error('[Telegram] Invalid JSON: ' . json_encode(['method' => $method, 'body' => $response['body']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => false, 'error' => 'invalid_json'];
        }

        if (empty($json['ok'])) {
            Log::error('[Telegram] API error: ' . json_encode(['method' => $method, 'response' => $json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $json;
    }

    public function send(string $text, ?int $chat_id = null, array $opts = []): array
    {
        $chat = $chat_id ?? $this->chat_id;
        if ($chat === null) {
            Log::error('[Telegram] chat_id is empty');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }

        $params = ['chat_id' => $chat, 'text' => $text] + $opts;
        return $this->api('sendMessage', $params);
    }

    public function send_photo(string $photo, array $opts = []): array
    {
        $chat_id = $opts['chat_id'] ?? $this->chat_id;
        if ($chat_id === null) {
            Log::error('[Telegram] chat_id is empty for send_photo');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
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
        return $this->api('send_photo', $params);
    }

    public function send_document(string $document, array $opts = []): array
    {
        $chat_id = $opts['chat_id'] ?? $this->chat_id;
        if ($chat_id === null) {
            Log::error('[Telegram] chat_id is empty for send_document');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
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
        return $this->api('send_document', $params);
    }

    public function get_me(): array
    {
        return $this->api('get_me');
    }

    public function create_invoice_link(array $data): ?string
    {
        if (isset($data['prices']) && !is_string($data['prices'])) {
            $data['prices'] = json_encode($data['prices'], JSON_UNESCAPED_UNICODE);
        }
        $response = $this->api('create_invoice_link', $data);
        return (!empty($response['ok']) && isset($response['result'])) ? (string)$response['result'] : null;
    }

    public function send_invoice(array $data): array
    {
        if (!isset($data['chat_id'])) {
            $data['chat_id'] = $this->chat_id;
        }
        if ($data['chat_id'] === null) {
            Log::error('[Telegram] chat_id is empty for send_invoice');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
        return $this->api('send_invoice', $data);
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

    public function verify_login_widget(array $auth_data, ?string $bot_token = null): array|false
    {
        $token = $bot_token ?: $this->token;
        if (empty($token)) {
            Log::error('[Telegram] Bot token is empty for verify_login_widget');
            return false;
        }

        if (empty($auth_data['hash']) || empty($auth_data['id']) || empty($auth_data['auth_date'])) {
            Log::error('[Telegram] verify_login_widget missing required fields');
            return false;
        }

        $auth_date = (int)$auth_data['auth_date'];
        $expiry = DAY_IN_SECONDS;
        if ((time() - $auth_date) > $expiry) {
            Log::error('[Telegram] verify_login_widget: auth data is outdated');
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
        return $this->api('answer_pre_checkout_query', $packet);
    }

    public function answer_shipping_query(string $shipping_query_id, bool $ok, array $shipping_options = [], string $error_message = ''): array
    {
        $packet = ['shipping_query_id' => $shipping_query_id, 'ok' => $ok];
        if ($ok) $packet['shipping_options'] = json_encode($shipping_options, JSON_UNESCAPED_UNICODE);
        if (!$ok && $error_message !== '') $packet['error_message'] = $error_message;
        return $this->api('answer_shipping_query', $packet);
    }

    public function set_webhook(string $url, array $opts = []): array
    {
        $packet = ['url' => $url] + $opts;
        return $this->api('set_webhook', $packet);
    }

    public function delete_webhook(bool $drop_pending_updates = false): array
    {
        return $this->api('delete_webhook', ['drop_pending_updates' => $drop_pending_updates]);
    }

    public function set_chat_menu_button(string $text, string $url, string $type = 'web_app'): array
    {
        return $this->api('set_chat_menu_button', [
            'menu_button' => [
                'type' => $type,
                'text' => $text,
                'web_app' => ['url' => $url],
            ],
        ]);
    }

    public function delete_chat_menu_button(): array
    {
        return $this->api('set_chat_menu_button', [
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
        return $this->api('set_my_description', $params);
    }

    public function set_my_short_description(string $short_description, string $language_code = ''): array
    {
        $params = ['short_description' => $short_description];
        if ($language_code !== '') {
            $params['language_code'] = $language_code;
        }
        return $this->api('set_my_short_description', $params);
    }

    public function set_my_name(string $name, string $language_code = ''): array
    {
        $params = ['name' => $name];
        if ($language_code !== '') {
            $params['language_code'] = $language_code;
        }
        return $this->api('set_my_name', $params);
    }

    private function __clone() {}
}