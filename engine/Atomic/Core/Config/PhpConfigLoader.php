<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

if (!defined( 'ATOMIC_START' ) ) exit;

class PhpConfigLoader { 
    use PathResolutionTrait;
    use ConfigHiveTrait;

    protected \Base $atomic;
    protected string $config_path;
    protected array $configs = [];

    public function __construct(\Base $atomic) {
        $this->atomic = $atomic;
        $this->config_path = ATOMIC_CONFIG;
    }

    public function load_config(string $name): array {
        $config_file = $this->config_path . $name . '.php';
        $resolved_config_file = realpath($config_file);
        if ($resolved_config_file !== false && is_file($resolved_config_file) && is_readable($resolved_config_file)) {
            return require $resolved_config_file;
        }
        return [];
    }

    protected function cfg(string $file, string $key, mixed $default = null): mixed {
        return $this->configs[$file][$key] ?? $default;
    }

    protected function cfg_nested(string $file, string $dot_path, mixed $default = null): mixed {
        $keys = explode('.', $dot_path);
        $value = $this->configs[$file] ?? [];
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function load(): array {
        $config_files = [
            'app', 'auth', 'cache', 'database', 'filesystems',
            'i18n', 'logging', 'mail', 'middleware', 'queue',
            'session', 'tools', 'providers'
        ];
        foreach ($config_files as $config) {
            $this->configs[$config] = $this->load_config($config);
        }

        $default_conn = (string)$this->cfg('database', 'default', 'mysql');
        $conn = $this->cfg_nested('database', "connections.{$default_conn}", []);
        $redis = $this->cfg('database', 'redis', []);
        $memc = $this->cfg('database', 'memcached', []);
        $mail = $this->configs['mail'] ?? [];

        $ws = $this->cfg('app', 'websocket', []);

        // ── Cache string (mirrors ConfigLoader) ──
        $cache_driver = strtolower((string)$this->cfg('cache', 'default', 'false'));
        $is_redis     = ($cache_driver === 'redis');
        $db_port        = (string)($conn['port'] ?? '3306');
        $redis_port     = (string)($redis['port'] ?? '6379');
        $memcached_port = (string)($memc['port'] ?? '11211');
        $cache_port     = (string)$this->cfg('cache', 'port', $is_redis ? $redis_port : $memcached_port);
        $ports = [
            'cache'     => $cache_port,
            'db'        => $db_port,
            'redis'     => $redis_port,
            'memcached' => $memcached_port,
            'mail'      => (string)($mail['port'] ?? '587'),
            'ws'        => (string)($ws['port'] ?? '8080'),
        ];

        $ws_host = (string)($ws['host'] ?? '0.0.0.0');
        $ws_client_host = (string)($ws['client_host'] ?? '127.0.0.1');

        $cache_string = $this->build_cache_string(
            $cache_driver,
            $this->fix_path((string)$this->cfg('cache', 'path', 'storage/framework/cache/')),
            (string)$this->cfg('cache', 'server', $is_redis ? '127.0.0.1' : 'localhost'),
            $ports['cache'],
            (string)$this->cfg('cache', 'password', ''),
            (string)$this->cfg('cache', 'login',    '')
        );

        // ── Paths ──
        $paths  = $this->cfg('app', 'paths', []);
        $ui_path = $paths['ui'] ?? 'public/themes/';

        // ── Settings array (mirrors ConfigLoader $settings) ──
        $settings = [
            'APP_UUID'              => (string)$this->cfg('app', 'uuid', ''),
            'CACHE'                 => $cache_string,
            'CACHE_PREFIX'          => (string)$this->cfg('cache', 'prefix', 'atomic_'),
            'DOMAIN'                => (string)$this->cfg('app', 'domain', ''),
            'LANGUAGE'              => (string)$this->cfg('app', 'language', 'en'),
            'FALLBACK'              => (string)$this->cfg('app', 'fallback', 'en'),
            'ENCODING'              => (string)$this->cfg('app', 'encoding', 'UTF-8'),
            'TZ'                    => (string)$this->cfg('app', 'timezone', 'UTC'),
            'APP_NAME'              => (string)$this->cfg('app', 'name', 'Atomic'),
            'APP_KEY'               => (string)$this->cfg('app', 'key', ''),
            'DEBUG_MODE'            => $this->cfg('app', 'debug', false) ? 'true' : 'false',
            'DEBUG_LEVEL'           => (string)$this->cfg('app', 'debug_level', 'error'),
            'ESCAPE'                => $this->cfg('app', 'escape', false) ? 'true' : 'false',
            'TELEMETRY_ADMIN_ONLY'  => (bool)$this->cfg_nested('app', 'telemetry.admin_only', false),
            'THEME.envname'         => (string)$this->cfg('app', 'theme', 'default'),
            'QUEUE_DRIVER'          => (string)$this->cfg('queue', 'driver', 'database'),
            'QUEUE_NAME'            => (string)$this->cfg('queue', 'name', 'default'),
            'TELEGRAM_BOT_TOKEN'    => (string)$this->cfg('tools', 'telegram_bot_token', ''),
            'TELEGRAM_CHAT_ID'      => (string)$this->cfg('tools', 'telegram_chat_id', ''),
            'TELEGRAM_LOG_LEVEL'    => (string)$this->cfg('tools', 'telegram_log_level', 'error'),
            'UI'                    => $ui_path,
            'TEMP'                  => $this->fix_path($paths['temp'] ?? 'storage/framework/cache/data/'),
            'LOGS'                  => $this->fix_path($paths['logs'] ?? 'storage/logs/'),
            'LOCALES'               => $this->fix_path($paths['locales'] ?? 'engine/Atomic/Lang/locales/'),
            'FONTS'                 => $this->fix_path($paths['fonts'] ?? 'engine/Atomic/Files/fonts/'),
            'FONTS_TEMP'            => $this->fix_path($paths['fonts_temp'] ?? 'storage/framework/cache/fonts/'),
            'MIGRATIONS'            => $this->fix_path($paths['migrations'] ?? 'database/migrations/'),
            'MIGRATIONS_BUNDLED'    => $this->fix_path($paths['migrations'] ?? 'database/migrations/') . 'atomic/',
            'MIGRATIONS_CORE'       => $this->fix_path($paths['migrations_core'] ?? 'Atomic/Core/Database/Migrations/'),
            'SEEDS'                 => $this->fix_path($paths['seeds'] ?? 'database/seeds/'),
            'SEEDS_BUNDLED'         => $this->fix_path($paths['seeds'] ?? 'database/seeds/') . 'atomic/',
            'USER_PLUGINS'          => $this->fix_path($paths['user_plugins'] ?? 'public/plugins/'),
            'FRAMEWORK_ROUTES'      => $this->fix_path($paths['framework_routes'] ?? 'Atomic/Core/Routes/'),
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

        // ── DB_CONFIG ──
        $this->atomic->set('DB_CONFIG', [
            'driver'                 => (string)($conn['driver'] ?? $default_conn),
            'host'                   => (string)($conn['host'] ?? '127.0.0.1'),
            'port'                   => $ports['db'],
            'database'               => (string)($conn['database'] ?? ''),
            'username'               => (string)($conn['username'] ?? ''),
            'password'               => (string)($conn['password'] ?? ''),
            'unix_socket'            => (string)($conn['unix_socket'] ?? ''),
            'charset'                => (string)($conn['charset'] ?? 'utf8mb4'),
            'collation'              => (string)($conn['collation'] ?? 'utf8mb4_unicode_ci'),
            'ATOMIC_DB_PREFIX'       => 'atomic_',
            'ATOMIC_DB_QUEUE_PREFIX' => 'atomic_queue_',
        ]);

        // ── REDIS ──
        $this->atomic->set('REDIS', [
            'host'                        => (string)($redis['host'] ?? '127.0.0.1'),
            'port'                        => $ports['redis'],
            'ATOMIC_REDIS_PREFIX'         => 'atomic.',
            'ATOMIC_REDIS_QUEUE_PREFIX'   => 'atomic.queue.',
            'ATOMIC_REDIS_SESSION_PREFIX' => 'atomic.session.',
        ]);

        // ── MEMCACHED ──
        $this->atomic->set('MEMCACHED', [
            'port'     => $ports['memcached'],
            'host'     => (string)($memc['host'] ?? '127.0.0.1'),
            'username' => (string)($memc['username'] ?? ''),
            'password' => (string)($memc['password'] ?? ''),
            'prefix'   => (string)($memc['prefix'] ?? 'atomic_'),
        ]);

        // ── MUTEX ──
        $mutex = $this->cfg('database', 'mutex', []);
        $this->atomic->set('MUTEX', [
            'driver' => (string)($mutex['driver'] ?? ''),
        ]);

        // ── MAIL ──
        $this->atomic->set('MAIL', [
            'driver'       => (string)($mail['driver'] ?? 'smtp'),
            'host'         => (string)($mail['host'] ?? 'smtp.example.com'),
            'port'         => $ports['mail'],
            'username'     => (string)($mail['username'] ?? ''),
            'password'     => (string)($mail['password'] ?? ''),
            'encryption'   => (string)($mail['encryption'] ?? 'tls'),
            'from_address' => (string)($mail['from_address'] ?? ''),
            'from_name'    => (string)($mail['from_name'] ?? ''),
        ]);

        // ── SESSION_CONFIG ──
        $session = $this->configs['session'] ?? [];
        $this->atomic->set('SESSION_CONFIG', [
            'driver'          => (string)($session['driver'] ?? 'db'),
            'lifetime'        => (string)($session['lifetime'] ?? '7200'),
            'cookie'          => (string)($session['cookie'] ?? 'atomicsession'),
            'kill_on_suspect' => (bool)($session['kill_on_suspect'] ?? true),
        ]);

        // ── JAR (cookie settings) ──
        $this->atomic->set('JAR.lifetime', (int)($session['cookie_expire'] ?? 0));
        $this->atomic->set('JAR.path',     (string)($session['cookie_path'] ?? '/'));
        $this->atomic->set('JAR.domain',   (string)($session['cookie_domain'] ?? ''));
        $this->atomic->set('JAR.secure',   (bool)($session['cookie_secure'] ?? false));
        $this->atomic->set('JAR.httponly',  (bool)($session['cookie_httponly'] ?? true));
        $this->atomic->set('JAR.samesite', (string)($session['cookie_samesite'] ?? 'Lax'));

        // ── CORS ──
        $cors = $this->cfg('app', 'cors', []);
        $this->atomic->set('CORS', [
            'headers'     => (string)($cors['headers'] ?? 'Content-Type,Authorization'),
            'origin'      => (string)($cors['origin'] ?? '*'),
            'credentials' => (bool)($cors['credentials'] ?? false),
            'expose'      => (string)($cors['expose'] ?? 'Authorization'),
            'ttl'         => (int)($cors['ttl'] ?? 0),
        ]);

        // ── RATE_LIMIT ──
        $rate_limit = $this->cfg('auth', 'rate_limit', []);
        $this->atomic->set('RATE_LIMIT', [
            'register' => [
                'ip'             => (int)($rate_limit['register']['ip'] ?? 10),
                'credential'     => (int)($rate_limit['register']['credential'] ?? 3),
                'ip_ttl'         => (int)($rate_limit['register']['ip_ttl'] ?? 3600),
                'credential_ttl' => (int)($rate_limit['register']['credential_ttl'] ?? 86400),
            ],
            'login' => [
                'ip'             => (int)($rate_limit['login']['ip'] ?? 20),
                'credential'     => (int)($rate_limit['login']['credential'] ?? 5),
                'ip_ttl'         => (int)($rate_limit['login']['ip_ttl'] ?? 3600),
                'credential_ttl' => (int)($rate_limit['login']['credential_ttl'] ?? 1800),
            ],
        ]);

        // ── QUEUE ──
        $queue_config = $this->configs['queue'] ?? [];
        $queue = [];
        foreach (['database', 'redis'] as $driver) {
            $driver_queues = $queue_config[$driver]['queues'] ?? ['default' => []];
            $defaults = [
                'delay' => 0, 'priority' => 10, 'timeout' => 20,
                'max_attempts' => 3, 'retry_delay' => 2,
                'worker_cnt' => 5, 'batch_size' => 10, 'ttl' => 604800,
            ];
            $processed_default = [];
            $default_queue = $driver_queues['default'] ?? [];
            foreach ($defaults as $k => $v) {
                $processed_default[$k] = (int)($default_queue[$k] ?? $v);
            }
            $processed_queues = ['default' => $processed_default];
            foreach ($driver_queues as $queue_name => $queue_cfg) {
                if ($queue_name === 'default') continue;
                $processed = [];
                foreach ($defaults as $k => $v) {
                    $processed[$k] = (int)($queue_cfg[$k] ?? $processed_default[$k]);
                }
                $processed_queues[$queue_name] = $processed;
            }
            $queue[$driver] = ['queues' => $processed_queues];
        }
        $this->atomic->set('QUEUE', $queue);

        // ── i18n ──
        $i18n_config = $this->cfg_nested('i18n', 'i18n', []);
        $this->atomic->set('i18n', [
            'languages' => $i18n_config['languages'] ?? ['en', 'ru'],
            'default'   => (string)($i18n_config['default'] ?? 'en'),
            'url_mode'  => (string)($i18n_config['url_mode'] ?? 'prefix'),
            'ttl'       => (int)($i18n_config['ttl'] ?? 3600),
            'cookie'    => (string)($i18n_config['cookie'] ?? 'lang'),
            'session'   => (string)($i18n_config['session'] ?? 'lang'),
        ]);

        // ── OAUTH ──
        $oauth = $this->cfg('auth', 'oauth', []);
        $this->atomic->set('OAUTH', [
            'google' => [
                'client_id'     => (string)($oauth['google']['client_id'] ?? ''),
                'client_secret' => (string)($oauth['google']['client_secret'] ?? ''),
                'redirect_uri'  => (string)($oauth['google']['redirect_uri'] ?? ''),
            ],
            'telegram' => [
                'bot_username'  => (string)($oauth['telegram']['bot_username'] ?? ''),
                'bot_token'     => (string)($oauth['telegram']['bot_token'] ?? ''),
                'callback_url'  => (string)($oauth['telegram']['callback_url'] ?? '/auth/telegram/callback'),
            ],
        ]);

        // ── MONOPAY ──
        $monopay = $this->cfg('tools', 'monopay', []);
        $this->atomic->set('MONOPAY.TOKEN',        (string)($monopay['token'] ?? ''));
        $this->atomic->set('MONOPAY.TEST_MODE',    (bool)($monopay['test_mode'] ?? false));
        $this->atomic->set('MONOPAY.WEBHOOK_URL',  (string)($monopay['webhook_url'] ?? ''));
        $this->atomic->set('MONOPAY.REDIRECT_URL', (string)($monopay['redirect_url'] ?? ''));

        // ── AI ──
        $ai = $this->cfg('tools', 'ai', []);
        $this->atomic->set('ai', [
            'openai'     => ['api_key' => (string)($ai['openai']['api_key'] ?? '')],
            'groq'       => ['api_key' => (string)($ai['groq']['api_key'] ?? '')],
            'openrouter' => ['api_key' => (string)($ai['openrouter']['api_key'] ?? '')],
            'globus'     => ['api_key' => (string)($ai['globus']['api_key'] ?? '')],
        ]);

        return $this->configs;
    }

    public function get(string $key, mixed $default = null): mixed {
        $parts = explode('.', $key);
        $config = $this->configs;

        foreach ($parts as $part) {
            if (!isset($config[$part])) {
                return $default;
            }
            $config = $config[$part];
        }

        return $config;
    }
}