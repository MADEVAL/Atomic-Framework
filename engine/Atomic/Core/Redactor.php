<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

class Redactor
{
    public const MASKED = '[MASKED]';

    private static string $home_path = '';

    /** @var array<string,string> Cached search/replace pairs for home-path masking. */
    private static array $home_variants = [];

    public static function set_home_path(string $path): void
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || $path === self::$home_path) return;

        self::$home_path = $path;
        self::rebuild_home_variants();
    }

    public static function sync_from_hive(\Base $atomic): void
    {
        $home = (string)$atomic->get('HOME');
        if ($home === '') {
            $root = rtrim((string)$atomic->get('ROOT'), '/\\');
            if ($root !== '') {
                $home = dirname($root);
            }
        }
        if ($home !== '') {
            self::set_home_path($home);
        }
    }

    public static function is_ready(): bool
    {
        return self::$home_path !== '';
    }

    public static function get_home_path(): string
    {
        return self::$home_path;
    }

    private static function rebuild_home_variants(): void
    {
        $canonical = self::$home_path;
        $forward   = str_replace('\\', '/', $canonical);
        $back      = str_replace('/', '\\', $canonical);

        $variants = [];
        foreach ([$canonical, $forward, $back] as $base) {
            $variants[$base . '/']  = '[HOME]/';
            $variants[$base . '\\'] = '[HOME]\\';
            $variants[$base]        = '[HOME]';
        }

        self::$home_variants = $variants;
    }

    protected const SENSITIVE_KEYS = [
        // ── credentials (long words - safe as substring match) ───────────
        '/password/i',
        '/passwd/i',
        // ── credentials (short - segment boundaries required) ────────────
        '/(?:^|[^a-zA-Z0-9])secret(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])seed(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])pass(?:$|[^a-zA-Z0-9])/i',
        // ── keys & tokens ────────────────────────────────────────────────
        '/(?:^|[^a-zA-Z0-9])key(?:$|[^a-zA-Z0-9])/i',     // KEY, APP_KEY, API_KEY
        '/(?:^|[^a-zA-Z0-9])token(?:$|[^a-zA-Z0-9])/i',   // TOKEN, ACCESS_TOKEN
        '/api[_\-]?key/i',                                  // api_key, apikey, api-key
        '/access[_\-]?token/i',
        '/refresh[_\-]?token/i',
        // ── auth & identity (NOT "author", "authority", …) ───────────────
        '/(?:^|[^a-zA-Z0-9])auth(?:$|[^a-zA-Z0-9])/i',    // auth, x_auth, AUTH
        '/authorization/i',                                  // unambiguous long word
        '/(?:^|[^a-zA-Z0-9])bearer(?:$|[^a-zA-Z0-9])/i',
        '/credential/i',
        '/(?:^|[^a-zA-Z0-9])login(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])username(?:$|[^a-zA-Z0-9])/i',
        '/^user$/i',                                         // exact - avoids user_agent
        // ── network / server ─────────────────────────────────────────────
        '/addr$/i',                                          // REMOTE_ADDR, SERVER_ADDR
        '/^home$/i',                                         // exact
        // ── crypto & signing ─────────────────────────────────────────────
        '/encryption/i',
        '/(?:^|[^a-zA-Z0-9])hmac(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])nonce(?:$|[^a-zA-Z0-9])/i',
        '/signature/i',
        '/(?:^|[^a-zA-Z0-9])private/i',
        // ── session & cookies ────────────────────────────────────────────
        '/(?:^|[^a-zA-Z0-9])cookie(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])session(?:$|[^a-zA-Z0-9])/i',
        '/set[_\-]cookie/i',
        // ── database ─────────────────────────────────────────────────────
        '/(?:^|[^a-zA-Z0-9])dsn(?:$|[^a-zA-Z0-9])/i',
        '/(?:^|[^a-zA-Z0-9])(?:db|database)$/i',
        // ── chat / app ───────────────────────────────────────────────────
        '/chat[_\-]?id(?:$|[^a-zA-Z0-9])/i',
        '/^app[_\-]?uuid$/i',                               // APP_UUID - app instance identifier
        '/client[_\-]?id/i',                                // OAuth client_id
        // ── HTTP headers ─────────────────────────────────────────────────
        '/x[_\-]api[_\-]key/i',
        '/x[_\-]auth(?:$|[^a-zA-Z0-9])/i',                 // NOT x_author
        '/x[_\-]csrf/i',
        '/x[_\-]xsrf/i',
    ];

    protected const SENSITIVE_VALUE_MAPPING = [
        // Authorization: Bearer / Basic / Token ...
        '/((?:Authorization|Proxy-Authorization)\s*[:=]\s*)([^\r\n]+)/i'
            => '$1' . self::MASKED,
        // Bearer <token> anywhere
        '/(Bearer\s+)(\S+)/i'
            => '$1' . self::MASKED,
        // Basic <base64> anywhere
        '/(Basic\s+)([A-Za-z0-9+\/=]+)/i'
            => '$1' . self::MASKED,
        // URL credentials  scheme://user:pass@host
        '/(:\\/\\/[^:\\/\s]+:)([^@\s]+)(@)/i'
            => '$1' . self::MASKED . '$3',
        // Inline key=value for known sensitive params
        '/((?:password|passwd|secret|token|api_?key|access_?token|refresh_?token|authorization|auth|encryption_?key|app_?key|chat_?id|session_?cookie|cookie)\s*[:=]\s*)("?)([^\s"&,;]+)(\2)/i'
            => '$1$2' . self::MASKED . '$4',
        // JSON-style "key":"value" pairs for known sensitive params
        '/(("(?:password|passwd|secret|token|api_?key|access_?token|refresh_?token|authorization|auth|encryption_?key|app_?key|chat_?id|session_?cookie|cookie)"\s*:\s*")((?:\\\\.|[^"\\\\])*)("))/i'
            => '$2' . self::MASKED . '$4',
    ];

    public static function is_sensitive_key(string|int $key): bool
    {
        if (is_int($key)) return false;
        foreach (self::SENSITIVE_KEYS as $pattern) {
            if (preg_match($pattern, $key)) return true;
        }
        return false;
    }

    public static function sanitize_string(string $value): string
    {
        $value = preg_replace(
            array_keys(self::SENSITIVE_VALUE_MAPPING),
            array_values(self::SENSITIVE_VALUE_MAPPING),
            $value
        ) ?? $value;

        if (self::$home_variants !== []) {
            $value = str_replace(
                array_keys(self::$home_variants),
                array_values(self::$home_variants),
                $value
            );
        }

        return $value;
    }

    public static function normalize(mixed $data, int $depth = 0, int $max_depth = 6, int $max_items = 1000): mixed
    {
        if ($depth >= $max_depth) return '[[max_depth]]';
        if (is_null($data)) return $data;

        if (is_string($data)) return self::sanitize_string($data);
        if (is_scalar($data)) return $data;

        if (is_array($data)) {
            $out = [];
            $i = 0;
            foreach ($data as $key => $value) {
                if ($i++ >= $max_items) { $out['[[truncated]]'] = true; break; }
                // Keep nested structures traversable even under sensitive parent keys.
                if (self::is_sensitive_key($key) && !is_array($value) && !is_object($value)) {
                    $out[$key] = self::MASKED;
                } else {
                    $out[$key] = self::normalize($value, $depth + 1, $max_depth, $max_items);
                }
            }
            return $out;
        }

        if (is_object($data)) {
            if ($data instanceof \DateTimeInterface) return $data->format(\DateTimeInterface::RFC3339_EXTENDED);

            $cls = get_class($data);
            $props = [];
            foreach (get_object_vars($data) as $name => $value) {
                // Keep nested structures traversable even under sensitive parent keys.
                if (self::is_sensitive_key($name) && !is_array($value) && !is_object($value)) {
                    $props[$name] = self::MASKED;
                } else {
                    $props[$name] = self::normalize($value, $depth + 1, $max_depth, $max_items);
                }
            }

            $out = ['__object__' => $cls];
            if ($props !== []) $out['properties'] = $props;
            return $out;
        }

        if (is_resource($data)) return ['__resource__' => get_resource_type($data) ?: 'resource'];
        return '[[unsupported]]';
    }
}
