<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

use Engine\Atomic\Auth\ConfigUserStore;
use Engine\Atomic\Cache\FatFreeCacheBridge;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\RateLimit\RateLimiter;

if (!defined( 'ATOMIC_START' ) ) exit; 

class ConfigLoader {
    use PathResolutionTrait;
    use ConfigHiveTrait;

    protected \Base $atomic;
    protected array $env = [];

    public static function init(\Base $atomic, string $env_file): void {
        (new self($atomic))->load($env_file);
    }

    public function __construct(\Base $atomic) {
        $this->atomic = $atomic;
    }

    protected function parse_env(string $file): array {
        $data = [];
        if (file_exists($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if (empty($line) || $line[0] === '#') {
                    continue;
                }
                $comment_pos = strpos($line, '#');
                if ($comment_pos !== false) {
                    $line = trim(substr($line, 0, $comment_pos));
                }
                if (empty($line)) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $data[trim($parts[0])] = $this->strip_quotes(trim($parts[1]));
                }
            }
        }
        return $data;
    }

    protected function get_env(string $key, mixed $default = null): mixed {
        return $this->env[$key] ?? $default;
    }

    private function strip_quotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    public function load(string $file): void {
        $this->env = $this->parse_env($file);
        $cache_driver  = strtolower($this->get_env('CACHE_DRIVER', 'folder'));
        $cache_prefix  = (string)$this->get_env('CACHE_PREFIX', 'atomic.');
        $db_prefix = (string)$this->get_env('DB_PREFIX', 'atomic_');
        $redis_prefix = (string)$this->get_env('REDIS_PREFIX', $cache_prefix);
        $memcached_prefix = (string)$this->get_env('MEMCACHED_PREFIX', 'atomic.');
        $ports = [
            'db'        => (string)$this->get_env('DB_PORT', '3306'),
            'redis'     => (string)$this->get_env('REDIS_PORT', '6379'),
            'memcached' => (string)$this->get_env('MEMCACHED_PORT', '11211'),
            'mail'      => (string)$this->get_env('MAIL_PORT', '587'),
            'ws'        => (string)$this->get_env('WS_PORT', '8080'),
        ];

        $ws_host = (string)$this->get_env('WS_HOST', '0.0.0.0');
        $ws_client_host = (string)$this->get_env('WS_CLIENT_HOST', '127.0.0.1');
        $cache_path = $this->fix_path($this->get_env('CACHE_PATH', 'storage/framework/cache/'));
        $redis_config = [
            'host'     => $this->get_env('REDIS_HOST', '127.0.0.1'),
            'port'     => $ports['redis'],
            'password' => $this->get_env('REDIS_PASSWORD', ''),
            'db'       => (int)$this->get_env('REDIS_DB', 0),
            'prefix'   => $redis_prefix,
        ];
        $memcached_config = [
            'port'     => $ports['memcached'],
            'host'     => $this->get_env('MEMCACHED_HOST', '127.0.0.1'),
            'username' => $this->get_env('MEMCACHED_USERNAME', ''),
            'password' => $this->get_env('MEMCACHED_PASSWORD', ''),
            'prefix'   => $memcached_prefix,
        ];

        $this->register_framework_defaults();

        $ui_raw = $this->get_env('UI', 'public/themes/');
        $this->atomic->set('THEME.ENQ_UI', $ui_raw);

        $this->load_registered_schemas();

        $this->atomic->set('UI', ATOMIC_DIR . DIRECTORY_SEPARATOR . $this->atomic->get('UI'));
        $this->atomic->set('ENQ_UI_FIX', $this->atomic->get('UI'));

        $this->atomic->set('CACHE_CONFIG', [
            'default' => $cache_driver,
            'path'    => $cache_path,
            'prefix'  => $cache_prefix,
        ]);

        $this->atomic->set('MIGRATIONS_BUNDLED', $this->fix_path($this->get_env('MIGRATIONS', 'database/migrations/') . 'atomic/'));
        $this->atomic->set('SEEDS_BUNDLED', $this->fix_path($this->get_env('SEEDS', 'database/seeds/') . 'atomic/'));
        $this->atomic->set('TELEGRAM_BOT_TOKEN', $this->get_env('TELEGRAM_BOT_TOKEN', ''));
        $this->atomic->set('TELEGRAM_CHAT_ID', $this->get_env('TELEGRAM_CHAT_ID', ''));

        $this->atomic->set('PORTS', $ports);
        $this->atomic->set('WS', [
            'host' => $ws_host,
            'client_host' => $ws_client_host,
            'port' => (int)$ports['ws'],
            'listen' => 'tcp://' . $ws_host . ':' . $ports['ws'],
            'url' => 'ws://' . $ws_client_host . ':' . $ports['ws'],
        ]);

        $this->atomic->set('DB_CONFIG', [
            'driver'      => $this->get_env('DB_DRIVER'),
            'host'        => $this->get_env('DB_HOST', '127.0.0.1'),
            'port'        => $ports['db'],
            'db'          => $this->get_env('DB_DB', ''),
            'username'    => $this->get_env('DB_USERNAME', ''),
            'password'    => $this->get_env('DB_PASSWORD', ''),
            'unix_socket' => $this->get_env('DB_SOCKET', ''),
            'charset' => $this->get_env('DB_CHARSET', 'utf8mb4'),
            'collation' => $this->get_env('DB_COLLATION', 'utf8mb4_general_ci'),
            'prefix'    => $db_prefix,
        ]);

        $this->atomic->set('REDIS', $redis_config);
        $this->atomic->set('MEMCACHED', $memcached_config);

        $this->atomic->set('MUTEX', [
            'driver' => $this->get_env('MUTEX_DRIVER', ''),
        ]);

        $this->atomic->set('MAIL', [
            'driver'       => $this->get_env('MAIL_DRIVER', 'smtp'),
            'host'         => $this->get_env('MAIL_HOST', '127.0.0.1'),
            'port'         => $ports['mail'],
            'username'     => $this->get_env('MAIL_USERNAME', ''),
            'password'     => $this->get_env('MAIL_PASSWORD', ''),
            'encryption'   => $this->get_env('MAIL_ENCRYPTION', 'tls'),
            'from_address' => $this->get_env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
            'from_name'    => $this->get_env('MAIL_FROM_NAME', 'Atomic'),
        ]);
        $this->apply_mail_settings_to_hive($this->atomic, (array)$this->atomic->get('MAIL'));

        $this->atomic->set('SESSION_CONFIG', [
            'driver'          => $this->get_env('SESSION_DRIVER', 'db'),
            'lifetime'        => $this->get_env('SESSION_LIFETIME', 259200),
            'cookie'          => $this->get_env('SESSION_COOKIE', 'Atomic_Session'),
            'kill_on_suspect' => filter_var($this->get_env('SESSION_KILL_ON_SUSPECT', true), FILTER_VALIDATE_BOOLEAN),
            'redis_prefix'    => $this->get_env('SESSION_REDIS_PREFIX', $redis_prefix . 'session.'),
        ]);

        $this->atomic->set('CORS', [
            'headers'     => $this->get_env('CORS_HEADERS', 'Content-Type,Authorization'),
            'origin'      => $this->get_env('CORS_ORIGIN', '*'),
            'credentials' => filter_var($this->get_env('CORS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),
            'expose'      => $this->get_env('CORS_EXPOSE', 'Authorization'),
            'ttl'         => (int)$this->get_env('CORS_TTL', 86400),
        ]);

        $this->atomic->set('RATE_LIMITER', [
            'fail'     => $this->get_env('RATE_LIMITER_FAIL', RateLimiter::FAIL_OPEN),
            'policies' => $this->build_rate_limiter_policies(),
        ]);

        $this->atomic->set('AUTH_RATE_LIMIT', [
            'max_attempts'    => (int)$this->get_env('AUTH_RATE_LIMIT_MAX_ATTEMPTS', 5),
            'window_seconds'  => (int)$this->get_env('AUTH_RATE_LIMIT_WINDOW_SECONDS', 300),
            'lockout_seconds' => (int)$this->get_env('AUTH_RATE_LIMIT_LOCKOUT_SECONDS', 900),
        ]);

        $this->atomic->set('ACCESS', [
            'guards' => (new ConfigUserStore(ATOMIC_DIR))->guards(),
        ]);

        $this->atomic->set('QUEUE', [
            'db' => [
                'queues' => $this->build_queue_config('QUEUE_DB_')
            ],
            'redis' => [
                'prefix' => $this->get_env('QUEUE_REDIS_PREFIX', $redis_prefix . 'queue.'),
                'queues' => $this->build_queue_config('QUEUE_REDIS_')
            ],
        ]);

        $this->atomic->set('i18n', [
            'languages' => array_filter(array_map('trim', explode(',', $this->get_env('I18N_LANGUAGES', 'en,ru')))),
            'default'   => $this->get_env('I18N_DEFAULT', 'en'),
            'url_mode'  => $this->get_env('I18N_URL_MODE', 'prefix'),
            'ttl'       => (int)$this->get_env('I18N_TTL', 0),
            'cookie'    => $this->get_env('I18N_COOKIE', 'lang'),
            'session'   => $this->get_env('I18N_SESSION', 'lang'),
        ]);

        $this->atomic->set('OAUTH', [
            'google' => [
                'client_id'     => $this->get_env('OAUTH_GOOGLE_CLIENT_ID', ''),
                'client_secret' => $this->get_env('OAUTH_GOOGLE_CLIENT_SECRET', ''),
                'redirect_uri'  => $this->get_env('OAUTH_GOOGLE_REDIRECT_URI', ''),
            ],
            'telegram' => [
                'bot_username'  => $this->get_env('OAUTH_TELEGRAM_BOT_USERNAME', ''),
                'bot_token'     => $this->get_env('OAUTH_TELEGRAM_BOT_TOKEN', ''),
                'callback_url'  => $this->get_env('OAUTH_TELEGRAM_CALLBACK_URL', '/auth/telegram/callback'),
            ],
        ]);

        $this->atomic->set('LOG_CHANNELS', [
            'default'  => $this->get_env('LOG_DEFAULT_CHANNEL', 'atomic'),
            'channels' => $this->build_log_channels(),
        ]);

        $this->atomic->set('CONFIG', $this->build_custom_config());

        $tz = $this->atomic->get('TZ');
        if ($tz) @date_default_timezone_set($tz);

        $cache_bridge = \Engine\Atomic\Cache\FatFreeCacheBridge::install();
        $cache_config = $this->atomic->get('CACHE_CONFIG');
        $f3_cache = $this->build_f3_cache_setting(is_array($cache_config) ? $cache_config : []);
        $this->atomic->set('CACHE', $f3_cache);
        CacheManager::instance()->resolve();
        $cache_bridge->load($f3_cache);

        $this->sync_domain_to_hive($this->atomic, ['DOMAIN' => $this->atomic->get('DOMAIN')]);
    }

    private function register_framework_defaults(): void
    {
        ConfigRegistry::register('APP_NAME', 'APP_NAME', 'Atomic');
        ConfigRegistry::register('APP_KEY', 'APP_KEY', '');
        ConfigRegistry::register('APP_UUID', 'APP_UUID', '');
        ConfigRegistry::register('APP_ENCRYPTION_KEY', 'APP_ENCRYPTION_KEY', '');
        ConfigRegistry::register('DOMAIN', 'DOMAIN', '');
        ConfigRegistry::register('LANGUAGE', 'LANGUAGE', 'en');
        ConfigRegistry::register('ENCODING', 'ENCODING', 'UTF-8');
        ConfigRegistry::register('TZ', 'TZ', 'UTC');
        ConfigRegistry::register('DEBUG_MODE', 'DEBUG_MODE', 'false');
        ConfigRegistry::register('DEBUG_LEVEL', 'DEBUG_LEVEL', 'error');
        ConfigRegistry::register('THEME.envname', 'THEME', 'default');
        ConfigRegistry::register('QUEUE_DRIVER', 'QUEUE_DRIVER', 'redis');
        ConfigRegistry::register('QUEUE_NAME', 'QUEUE_NAME', 'default');
        ConfigRegistry::register('TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_TOKEN', '');
        ConfigRegistry::register('TELEGRAM_CHAT_ID', 'TELEGRAM_CHAT_ID', '');
        ConfigRegistry::register('CACHE_PREFIX', 'CACHE_PREFIX', 'atomic.');

        ConfigRegistry::register('TELEMETRY_ACCESS_MODE', 'TELEMETRY_ACCESS_MODE', 'none', 'string',
            fn($v) => in_array(strtolower(trim((string)$v)), ['config', 'auth', 'none'], true)
                ? strtolower(trim((string)$v)) : 'none');

        ConfigRegistry::register('UI', 'UI', 'public/themes/');
        ConfigRegistry::register('TEMP', 'TEMP', 'storage/framework/cache/data/', 'path');
        ConfigRegistry::register('LOGS', 'LOGS', 'storage/logs/', 'path');
        ConfigRegistry::register('LOCALES', 'LOCALES', 'engine/Atomic/Lang/locales/', 'path');
        ConfigRegistry::register('FONTS', 'FONTS', 'storage/framework/fonts/', 'path');
        ConfigRegistry::register('FONTS_TEMP', 'FONTS_TEMP', 'storage/framework/cache/fonts/', 'path');
        ConfigRegistry::register('MIGRATIONS', 'MIGRATIONS', 'database/migrations/', 'path');
        ConfigRegistry::register('MIGRATIONS_CORE', 'MIGRATIONS_CORE', 'Atomic/Core/Database/Migrations/', 'path');
        ConfigRegistry::register('SEEDS', 'SEEDS', 'database/seeds/', 'path');
        ConfigRegistry::register('USER_PLUGINS', 'USER_PLUGINS', 'plugins/', 'path');
        ConfigRegistry::register('FRAMEWORK_ROUTES', 'FRAMEWORK_ROUTES', 'Atomic/Core/Routes/', 'path');

        ConfigRegistry::register('JAR.lifetime', 'COOKIE_EXPIRE', 259200, 'int');
        ConfigRegistry::register('JAR.path', 'COOKIE_PATH', '/');
        ConfigRegistry::register('JAR.domain', 'COOKIE_DOMAIN', '');
        ConfigRegistry::register('JAR.secure', 'COOKIE_SECURE', true, 'bool');
        ConfigRegistry::register('JAR.httponly', 'COOKIE_HTTPONLY', true, 'bool');
        ConfigRegistry::register('JAR.samesite', 'COOKIE_SAMESITE', 'Lax');

        ConfigRegistry::register('MONOPAY.TOKEN', 'MONOPAY_TOKEN', '');
        ConfigRegistry::register('MONOPAY.TEST_MODE', 'MONOPAY_TEST_MODE', false, 'bool');
        ConfigRegistry::register('MONOPAY.WEBHOOK_URL', 'MONOPAY_WEBHOOK_URL', '');
        ConfigRegistry::register('MONOPAY.REDIRECT_URL', 'MONOPAY_REDIRECT_URL', '');

        ConfigRegistry::register('ai.openai.api_key', 'AI_OPENAI_API_KEY', '');
        ConfigRegistry::register('ai.groq.api_key', 'AI_GROQ_API_KEY', '');
        ConfigRegistry::register('ai.openrouter.api_key', 'AI_OPENROUTER_API_KEY', '');
        ConfigRegistry::register('ai.globus.api_key', 'AI_GLOBUS_API_KEY', '');

        ConfigRegistry::register('TELEMETRY_ACCESS_ALLOWED_ROLES', 'TELEMETRY_ACCESS_ALLOWED_ROLES', 'admin', 'csv');

        ConfigRegistry::register('SECURITY_HEADERS.ENABLED', 'SECURITY_HEADERS_ENABLED', true, 'bool');
        ConfigRegistry::register('SECURITY_HEADERS.XFO', 'SECURITY_HEADERS_XFO', 'DENY');
        ConfigRegistry::register('SECURITY_HEADERS.HSTS', 'SECURITY_HEADERS_HSTS', '');
        ConfigRegistry::register('SECURITY_HEADERS.CSP', 'SECURITY_HEADERS_CSP', '');
    }

    private function load_registered_schemas(): void
    {
        foreach (ConfigRegistry::schemas() as $key => $def) {
            $value = $this->get_env($def['env'], $def['default']);
            $value = $this->cast_config_value($value, $def['type']);
            if ($def['map'] !== null) {
                $value = ($def['map'])($value);
            }
            $this->atomic->set($key, $value);
        }
    }

    private function cast_config_value(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'  => (int)$value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'path' => $this->fix_path((string)$value),
            'csv'  => array_values(array_filter(
                array_map('trim', explode(',', (string)$value)),
                static fn(string $item): bool => $item !== ''
            )),
            default => (string)$value,
        };
    }

    protected function build_log_channels(): array {
        $channels = [];
        foreach ($this->env as $key => $value) {
            if (preg_match('/^LOG_([A-Z][A-Z0-9_]+?)_(DRIVER|PATH|LEVEL|MAX_DAYS)$/', $key, $m)) {
                $channel = strtolower($m[1]);
                $field   = strtolower($m[2]);
                $channels[$channel][$field] = $value;
            }
        }
        return $channels;
    }

    protected function build_queue_config(string $prefix): array {
        $default_cfg = [
            'delay' => (int)$this->get_env($prefix . 'DEFAULT_DELAY', 0),
            'priority' => (int)$this->get_env($prefix . 'DEFAULT_PRIORITY', 10),
            'timeout' => (int)$this->get_env($prefix . 'DEFAULT_TIMEOUT', 20),
            'max_attempts' => (int)$this->get_env($prefix . 'DEFAULT_MAX_ATTEMPTS', 3),
            'retry_delay' => (int)$this->get_env($prefix . 'DEFAULT_RETRY_DELAY', 2),
            'worker_cnt' => (int)$this->get_env($prefix . 'DEFAULT_WORKER_CNT', 5),
            'ttl' => (int)$this->get_env($prefix . 'DEFAULT_TTL', 604800),
        ];

        $queues = ['default' => $default_cfg];

        $queue_names = [];
        foreach ($this->env as $key => $value) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '([A-Z0-9_]+?)_(RETRY_DELAY|MAX_ATTEMPTS|WORKER_CNT|PRIORITY|TIMEOUT|DELAY|TTL)$/', $key, $m)) {
                $queue_name = strtoupper($m[1]);
                if ($queue_name !== 'DEFAULT') {
                    $queue_names[$queue_name] = true;
                }
            }
        }

        foreach (array_keys($queue_names) as $queue_name) {
            $queue_name_lower = strtolower($queue_name);
            $queues[$queue_name_lower] = [
                'delay' => (int)$this->get_env($prefix . $queue_name . '_DELAY', $default_cfg['delay']),
                'priority' => (int)$this->get_env($prefix . $queue_name . '_PRIORITY', $default_cfg['priority']),
                'timeout' => (int)$this->get_env($prefix . $queue_name . '_TIMEOUT', $default_cfg['timeout']),
                'max_attempts' => (int)$this->get_env($prefix . $queue_name . '_MAX_ATTEMPTS', $default_cfg['max_attempts']),
                'retry_delay' => (int)$this->get_env($prefix . $queue_name . '_RETRY_DELAY', $default_cfg['retry_delay']),
                'worker_cnt' => (int)$this->get_env($prefix . $queue_name . '_WORKER_CNT', $default_cfg['worker_cnt']),
                'ttl' => (int)$this->get_env($prefix . $queue_name . '_TTL', $default_cfg['ttl']),
            ];
        }
        
        return $queues;
    }

    protected function build_rate_limiter_policies(): array {
        $policies = [];
        foreach ($this->env as $key => $value) {
            if (!preg_match('/^RATE_LIMITER_([A-Z0-9_]+?)_(STRATEGY|KEY|LIMIT|WINDOW)$/', $key, $m)) {
                continue;
            }

            $policy = strtolower($m[1]);
            $field = strtolower($m[2]);
            $policies[$policy][$field] = in_array($field, ['limit', 'window'], true) ? (int)$value : $value;
        }

        return $policies;
    }

    private function build_custom_config(): array
    {
        $config = [];
        foreach ($this->env as $key => $value) {
            if (!preg_match('/^CONFIG_([A-Z0-9]+)_(.+)$/', $key, $m)) {
                continue;
            }

            $namespace = strtolower($m[1]);
            $config_key = strtolower($m[2]);
            $config[$namespace][$config_key] = $this->parse_custom_env_value((string)$value);
        }

        return $config;
    }

    private function parse_custom_env_value(string $value): mixed
    {
        $normalized = strtolower($value);
        if ($normalized === 'true') {
            return true;
        }
        if ($normalized === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        if (preg_match('/^-?(?:\d+\.\d*|\d*\.\d+)$/', $value)) {
            return (float)$value;
        }
        if (str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        return $value;
    }
}
