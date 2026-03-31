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
    protected string|int|null $chatId = null;

    private function __construct(?string $token = null, string|int|null $chatId = null)
    {
        $this->atomic = App::instance();
        $this->token = $token ?: (string)($this->atomic->get('TELEGRAM_BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: '');
        $this->chatId = $chatId ?: ($this->atomic->get('TELEGRAM_CHAT_ID') ?: getenv('TELEGRAM_CHAT_ID') ?: null);
    }

    public static function instance(?string $token = null, string|int|null $chatId = null): self
    {
        if ($token !== null || $chatId !== null) {
            return new self($token, $chatId);
        }
        return self::$instance ??= new self();
    }

    public function setToken(string $token): self
    {
        $this->token = trim($token);
        return $this;
    }

    public function setChatId(string|int $chatId): self
    {
        $this->chatId = $chatId;
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

    public function send(string $text, ?int $chatId = null, array $opts = []): array
    {
        $chat = $chatId ?? $this->chatId;
        if ($chat === null) {
            Log::error('[Telegram] chat_id is empty');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }

        $params = ['chat_id' => $chat, 'text' => $text] + $opts;
        return $this->api('sendMessage', $params);
    }

    public function sendPhoto(string $photo, array $opts = []): array
    {
        $chatId = $opts['chat_id'] ?? $this->chatId;
        if ($chatId === null) {
            Log::error('[Telegram] chat_id is empty for sendPhoto');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
        $params = ['chat_id' => $chatId];
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

    public function sendDocument(string $document, array $opts = []): array
    {
        $chatId = $opts['chat_id'] ?? $this->chatId;
        if ($chatId === null) {
            Log::error('[Telegram] chat_id is empty for sendDocument');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
        $params = ['chat_id' => $chatId];
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

    public function getMe(): array
    {
        return $this->api('getMe');
    }

    public function createInvoiceLink(array $data): ?string
    {
        if (isset($data['prices']) && !is_string($data['prices'])) {
            $data['prices'] = json_encode($data['prices'], JSON_UNESCAPED_UNICODE);
        }
        $response = $this->api('createInvoiceLink', $data);
        return (!empty($response['ok']) && isset($response['result'])) ? (string)$response['result'] : null;
    }

    public function sendInvoice(array $data): array
    {
        if (!isset($data['chat_id'])) {
            $data['chat_id'] = $this->chatId;
        }
        if ($data['chat_id'] === null) {
            Log::error('[Telegram] chat_id is empty for sendInvoice');
            return ['ok' => false, 'error' => 'empty_chat_id'];
        }
        return $this->api('sendInvoice', $data);
    }

    public function verifyWebAppInitData(string|array $initData): array|false
    {
        $data = is_array($initData) ? $initData : (function ($s) { parse_str($s, $out); return $out; })($initData);
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

    public function verifyLoginWidget(array $authData, ?string $botToken = null): array|false
    {
        $token = $botToken ?: $this->token;
        if (empty($token)) {
            Log::error('[Telegram] Bot token is empty for verifyLoginWidget');
            return false;
        }

        if (empty($authData['hash']) || empty($authData['id']) || empty($authData['auth_date'])) {
            Log::error('[Telegram] verifyLoginWidget missing required fields');
            return false;
        }

        $auth_date = (int)$authData['auth_date'];
        $expiry = DAY_IN_SECONDS;
        if ((time() - $auth_date) > $expiry) {
            Log::error('[Telegram] verifyLoginWidget: auth data is outdated');
            return false;
        }

        $check_hash = $authData['hash'];
        unset($authData['hash']);

        ksort($authData);
        $data_check_parts = [];
        foreach ($authData as $k => $v) {
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data_check_parts[] = $k . '=' . $v;
        }
        $data_check_string = implode("\n", $data_check_parts);

        $secret_key = hash('sha256', $token, true);
        $hash_hex = hash_hmac('sha256', $data_check_string, $secret_key);

        if (hash_equals($hash_hex, $check_hash)) {
            $authData['hash'] = $check_hash;
            return $authData;
        }

        Log::error('[Telegram] verifyLoginWidget: hash mismatch');
        return false;
    }

    public function getWidgetAttributes(string $size = 'large', bool $requestAccess = false, bool $useAvatar = false, int $cornerRadius = 20): array
    {
        $cfg = App::instance()->get('OAUTH.telegram') ?: [];
        return [
            'telegram-login' => $cfg['bot_username'] ?? '',
            'size' => $size,
            'auth-url' => $cfg['callback_url'] ?? '/auth/telegram/callback',
            'userpic' => $useAvatar ? 'true' : 'false',
            'radius' => $cornerRadius,
            'request-access' => $requestAccess ? 'write' : null,
            'bot_id' => explode(':', $cfg['bot_token'])[0] ?? ''
        ];
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, string $errorMessage = ''): array
    {
        $packet = ['pre_checkout_query_id' => $preCheckoutQueryId, 'ok' => $ok];
        if (!$ok && $errorMessage !== '') $packet['error_message'] = $errorMessage;
        return $this->api('answerPreCheckoutQuery', $packet);
    }

    public function answerShippingQuery(string $shippingQueryId, bool $ok, array $shippingOptions = [], string $errorMessage = ''): array
    {
        $packet = ['shipping_query_id' => $shippingQueryId, 'ok' => $ok];
        if ($ok) $packet['shipping_options'] = json_encode($shippingOptions, JSON_UNESCAPED_UNICODE);
        if (!$ok && $errorMessage !== '') $packet['error_message'] = $errorMessage;
        return $this->api('answerShippingQuery', $packet);
    }

    public function setWebhook(string $url, array $opts = []): array
    {
        $packet = ['url' => $url] + $opts;
        return $this->api('setWebhook', $packet);
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): array
    {
        return $this->api('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    public function setChatMenuButton(string $text, string $url, string $type = 'web_app'): array
    {
        return $this->api('setChatMenuButton', [
            'menu_button' => [
                'type' => $type,
                'text' => $text,
                'web_app' => ['url' => $url],
            ],
        ]);
    }

    public function deleteChatMenuButton(): array
    {
        return $this->api('setChatMenuButton', [
            'menu_button' => [
                'type' => 'default',
            ],
        ]);
    }

    public function setMyDescription(string $description, string $languageCode = ''): array
    {
        $params = ['description' => $description];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        return $this->api('setMyDescription', $params);
    }

    public function setMyShortDescription(string $shortDescription, string $languageCode = ''): array
    {
        $params = ['short_description' => $shortDescription];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        return $this->api('setMyShortDescription', $params);
    }

    public function setMyName(string $name, string $languageCode = ''): array
    {
        $params = ['name' => $name];
        if ($languageCode !== '') {
            $params['language_code'] = $languageCode;
        }
        return $this->api('setMyName', $params);
    }

    private function __clone() {}
}