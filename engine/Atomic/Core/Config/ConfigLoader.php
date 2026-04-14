<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

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
                    $data[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        return $data;
    }

    protected function get_env(string $key, mixed $default = null): mixed {
        return $this->env[$key] ?? $default;
    }

    public function load(string $file): void {
        $this->env = $this->parse_env($file);
        $cache_driver  = strtolower($this->get_env('CACHE_DRIVER', 'false'));
        $is_redis      = ($cache_driver === 'redis');
        $ports = [
            'cache'     => (string)$this->get_env('CACHE_PORT', $is_redis ? '6379' : '11211'),
            'db'        => (string)$this->get_env('DB_PORT', '3306'),
            'redis'     => (string)$this->get_env('REDIS_PORT', '6379'),
            'memcached' => (string)$this->get_env('MEMCACHED_PORT', '11211'),
            'mail'      => (string)$this->get_env('MAIL_PORT', '587'),
            'ws'        => (string)$this->get_env('WS_PORT', '8080'),
        ];

        $ws_host = (string)$this->get_env('WS_HOST', '0.0.0.0');
        $ws_client_host = (string)$this->get_env('WS_CLIENT_HOST', '127.0.0.1');

        $cache_string  = $this->build_cache_string(
            $cache_driver,
            $this->fix_path($this->get_env('CACHE_PATH', 'storage/framework/cache/')),
            $this->get_env('CACHE_SERVER', $is_redis ? '127.0.0.1' : 'localhost'),
            $ports['cache'],
            $this->get_env('CACHE_PASSWORD', ''),
            $this->get_env('CACHE_LOGIN', '')
        );

        $settings = [
            'APP_UUID'              => $this->get_env('APP_UUID', ''),
            'CACHE'                 => $cache_string,
            'CACHE_PREFIX'          => $this->get_env('CACHE_PREFIX', 'atomic_'),
            'DOMAIN'                => $this->get_env('DOMAIN', ''),
            'LANGUAGE'              => $this->get_env('LANGUAGE') ?? $this->get_env('LANG', 'en'),
            'FALLBACK'              => $this->get_env('FALLBACK', 'en'),
            'ENCODING'              => $this->get_env('ENCODING', 'UTF-8'),
            'TZ'                    => $this->get_env('TZ', 'UTC'),
            'APP_NAME'              => $this->get_env('APP_NAME', 'Atomic'),
            'APP_KEY'               => $this->get_env('APP_KEY', ''),
            'DEBUG_MODE'            => $this->get_env('DEBUG_MODE', 'false'),
            'DEBUG_LEVEL'           => $this->get_env('DEBUG_LEVEL', 'error'),
            'ESCAPE'                => $this->get_env('ESCAPE', 'false'),
            'TELEMETRY_ADMIN_ONLY'  => filter_var($this->get_env('TELEMETRY_ADMIN_ONLY', 'false'), FILTER_VALIDATE_BOOLEAN),
            'THEME.envname'         => $this->get_env('THEME', 'default'),
            'QUEUE_DRIVER'          => $this->get_env('QUEUE_DRIVER', 'database'),
            'QUEUE_NAME'            => $this->get_env('QUEUE_NAME', 'default'), 
            'TELEGRAM_BOT_TOKEN'    => $this->get_env('TELEGRAM_BOT_TOKEN', ''), 
            'TELEGRAM_CHAT_ID'      => $this->get_env('TELEGRAM_CHAT_ID', ''),
            'TELEGRAM_LOG_LEVEL'    => $this->get_env('TELEGRAM_LOG_LEVEL', 'error'),
            'UI'                    => $this->get_env('UI', 'public/themes/'),
            'TEMP'                  => $this->fix_path($this->get_env('TEMP', 'storage/framework/cache/data/')),
            'LOGS'                  => $this->fix_path($this->get_env('LOGS', 'storage/logs/')),
            'LOCALES'               => $this->fix_path($this->get_env('LOCALES', 'engine/Atomic/Lang/locales/')),
            'FONTS'                 => $this->fix_path($this->get_env('FONTS', 'engine/Atomic/Files/fonts/')),
            'FONTS_TEMP'            => $this->fix_path($this->get_env('FONTS_TEMP', 'storage/framework/cache/fonts/')),
            'MIGRATIONS'            => $this->fix_path($this->get_env('MIGRATIONS', 'database/migrations/')),
            'MIGRATIONS_BUNDLED'    => $this->fix_path($this->get_env('MIGRATIONS', 'database/migrations/') . 'atomic/'),
            'MIGRATIONS_CORE'       => $this->fix_path($this->get_env('MIGRATIONS_CORE', 'Atomic/Core/Database/Migrations/')),
            'SEEDS'                 => $this->fix_path($this->get_env('SEEDS', 'database/seeds/')),
            'SEEDS_BUNDLED'         => $this->fix_path($this->get_env('SEEDS', 'database/seeds/') . 'atomic/'),
            'USER_PLUGINS'          => $this->fix_path($this->get_env('USER_PLUGINS', 'public/plugins/')),
            'FRAMEWORK_ROUTES'      => $this->fix_path($this->get_env('FRAMEWORK_ROUTES', 'Atomic/Core/Routes/')),
        ];

        $this->atomic->set('THEME.ENQ_UI', $settings['UI']);
        $settings['UI'] = ATOMIC_DIR . DIRECTORY_SEPARATOR . $settings['UI'];
        $settings['ENQ_UI_FIX'] = $settings['UI'];

        $this->atomic->set('PORTS', $ports);
        $this->atomic->set('WS', [
            'host' => $ws_host,
            'client_host' => $ws_client_host,
            'port' => (int)$ports['ws'],
            'listen' => 'tcp://' . $ws_host . ':' . $ports['ws'],
            'url' => 'ws://' . $ws_client_host . ':' . $ports['ws'],
        ]);

        $this->apply_settings_to_hive($this->atomic, $settings);

        $this->atomic->set('DB_CONFIG', [
            'driver'      => $this->get_env('DB_DRIVER'),
            'host'        => $this->get_env('DB_HOST', '127.0.0.1'),
            'port'        => $ports['db'],
            'database'    => $this->get_env('DB_DATABASE', ''),
            'username'    => $this->get_env('DB_USERNAME', ''),
            'password'    => $this->get_env('DB_PASSWORD', ''),
            'unix_socket' => $this->get_env('DB_SOCKET', ''),
            'charset' => $this->get_env('DB_CHARSET', 'utf8mb4'),
            'collation' => $this->get_env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'ATOMIC_DB_PREFIX'    => 'atomic_',
            'ATOMIC_DB_QUEUE_PREFIX'    => 'atomic_queue_',
        ]);

        $this->atomic->set('REDIS', [
            'host'                        => $this->get_env('REDIS_HOST', '127.0.0.1'),
            'port'                        => $ports['redis'],
            'ATOMIC_REDIS_PREFIX'         => 'atomic.',
            'ATOMIC_REDIS_QUEUE_PREFIX'   => 'atomic.queue.',
            'ATOMIC_REDIS_SESSION_PREFIX' => 'atomic.session.',
        ]);

        $this->atomic->set('MEMCACHED', [
            'port'     => $ports['memcached'],
            'host'     => $this->get_env('MEMCACHED_HOST', '127.0.0.1'),
            'username' => $this->get_env('MEMCACHED_USERNAME', ''),
            'password' => $this->get_env('MEMCACHED_PASSWORD', ''),
            'prefix'   => $this->get_env('MEMCACHED_PREFIX', 'atomic_'),
        ]);

        $this->atomic->set('MUTEX', [
            'driver' => $this->get_env('MUTEX_DRIVER', ''),
        ]);

        $this->atomic->set('MAIL', [
            'driver'       => $this->get_env('MAIL_DRIVER', 'smtp'),
            'host'         => $this->get_env('MAIL_HOST', 'smtp.example.com'),
            'port'         => $ports['mail'],
            'username'     => $this->get_env('MAIL_USERNAME', ''),
            'password'     => $this->get_env('MAIL_PASSWORD', ''),
            'encryption'   => $this->get_env('MAIL_ENCRYPTION', 'tls'),
            'from_address' => $this->get_env('MAIL_FROM_ADDRESS', ''),
            'from_name'    => $this->get_env('MAIL_FROM_NAME', ''),
        ]);

        $this->atomic->set('SESSION_CONFIG', [
            'driver'          => $this->get_env('SESSION_DRIVER', 'db'),
            'lifetime'        => $this->get_env('SESSION_LIFETIME', 7200),
            'cookie'          => $this->get_env('SESSION_COOKIE', 'atomicsession'),
            'kill_on_suspect' => filter_var($this->get_env('SESSION_KILL_ON_SUSPECT', true), FILTER_VALIDATE_BOOLEAN),
        ]);

        $this->atomic->set('JAR.lifetime', (int)$this->get_env('COOKIE_EXPIRE', 0));
        $this->atomic->set('JAR.path', $this->get_env('COOKIE_PATH', '/'));
        $this->atomic->set('JAR.domain', $this->get_env('COOKIE_DOMAIN', ''));
        $this->atomic->set('JAR.secure', filter_var($this->get_env('COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN));
        $this->atomic->set('JAR.httponly', filter_var($this->get_env('COOKIE_HTTPONLY', true), FILTER_VALIDATE_BOOLEAN));
        $this->atomic->set('JAR.samesite', $this->get_env('COOKIE_SAMESITE', 'Lax'));

        $this->atomic->set('CORS', [
            'headers'     => $this->get_env('CORS_HEADERS', 'Content-Type,Authorization'),
            'origin'      => $this->get_env('CORS_ORIGIN', '*'),
            'credentials' => filter_var($this->get_env('CORS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),
            'expose'      => $this->get_env('CORS_EXPOSE', 'Authorization'),
            'ttl'         => (int)$this->get_env('CORS_TTL', 0),
        ]);

        $this->atomic->set('RATE_LIMIT', [
            'register' => [
                'ip' => (int)$this->get_env('RATE_LIMIT_REGISTER_IP', 10),
                'credential' => (int)$this->get_env('RATE_LIMIT_REGISTER_CREDENTIAL', 3),
                'ip_ttl' => (int)$this->get_env('RATE_LIMIT_REGISTER_IP_TTL', 3600),
                'credential_ttl' => (int)$this->get_env('RATE_LIMIT_REGISTER_CREDENTIAL_TTL', 86400),
            ],
            'login' => [
                'ip' => (int)$this->get_env('RATE_LIMIT_LOGIN_IP', 20),
                'credential' => (int)$this->get_env('RATE_LIMIT_LOGIN_CREDENTIAL', 5),
                'ip_ttl' => (int)$this->get_env('RATE_LIMIT_LOGIN_IP_TTL', 3600),
                'credential_ttl' => (int)$this->get_env('RATE_LIMIT_LOGIN_CREDENTIAL_TTL', 1800),
            ],
        ]);

        $this->atomic->set('QUEUE', [
            'database' => [
                'queues' => $this->build_queue_config('QUEUE_DATABASE_')
            ],
            'redis' => [
                'queues' => $this->build_queue_config('QUEUE_REDIS_')
            ],
        ]);

        $this->atomic->set('i18n', [
            'languages' => array_filter(array_map('trim', explode(',', $this->get_env('I18N_LANGUAGES', 'en,ru')))),
            'default'   => $this->get_env('I18N_DEFAULT', 'en'),
            'url_mode'  => $this->get_env('I18N_URL_MODE', 'prefix'),
            'ttl'       => (int)$this->get_env('I18N_TTL', 3600),
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

        $this->atomic->set('MONOPAY.TOKEN', $this->get_env('MONOPAY_TOKEN', ''));
        $this->atomic->set('MONOPAY.TEST_MODE', filter_var($this->get_env('MONOPAY_TEST_MODE', 'false'), FILTER_VALIDATE_BOOLEAN));
        $this->atomic->set('MONOPAY.WEBHOOK_URL', $this->get_env('MONOPAY_WEBHOOK_URL', ''));
        $this->atomic->set('MONOPAY.REDIRECT_URL', $this->get_env('MONOPAY_REDIRECT_URL', ''));

        $this->atomic->set('ai', [
            'openai'     => ['api_key' => $this->get_env('AI_OPENAI_API_KEY', '')],
            'groq'       => ['api_key' => $this->get_env('AI_GROQ_API_KEY', '')],
            'openrouter' => ['api_key' => $this->get_env('AI_OPENROUTER_API_KEY', '')],
            'globus'     => ['api_key' => $this->get_env('AI_GLOBUS_API_KEY', '')],
        ]);
    }

    protected function build_log_channels(): array {
        $channels = [];
        foreach ($this->env as $key => $value) {
            if (preg_match('/^LOG_([A-Z][A-Z0-9_]+?)_(DRIVER|PATH|LEVEL)$/', $key, $m)) {
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
            'batch_size' => (int)$this->get_env($prefix . 'DEFAULT_BATCH_SIZE', 10),
            'ttl' => (int)$this->get_env($prefix . 'DEFAULT_TTL', 604800),
        ];

        $queues = ['default' => $default_cfg];

        $queue_names = [];
        foreach ($this->env as $key => $value) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '([A-Z0-9_]+?)_(RETRY_DELAY|MAX_ATTEMPTS|WORKER_CNT|BATCH_SIZE|PRIORITY|TIMEOUT|DELAY|TTL)$/', $key, $m)) {
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
                'batch_size' => (int)$this->get_env($prefix . $queue_name . '_BATCH_SIZE', $default_cfg['batch_size']),
                'ttl' => (int)$this->get_env($prefix . $queue_name . '_TTL', $default_cfg['ttl']),
            ];
        }
        
        return $queues;
    }
}