<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Config Parity Test
 *
 * Verifies that ConfigLoader (env-based) and PhpConfigLoader (PHP-based)
 * produce identical F3 hive values when reading the real project .env and config/ files.
 * No mock data - all assertions run against actual configuration.
 */
class ConfigParityTest extends TestCase
{
    /** Hive values captured after ConfigLoader runs */
    private static array $env_data = [];

    /** Hive values captured after PhpConfigLoader runs */
    private static array $php_data = [];

    /**
     * Every top-level hive key that both loaders write.
     * Flat scalars and nested arrays are both included; capture uses $f3->get()
     * which returns the full value regardless of depth.
     */
    private static array $all_keys = [
        // ── Flat scalars ──────────────────────────────────────────────────────
        'APP_UUID', 'CACHE', 'CACHE_PREFIX', 'DOMAIN', 'LANGUAGE', 'FALLBACK',
        'ENCODING', 'TZ', 'APP_NAME', 'APP_KEY', 'DEBUG_MODE', 'DEBUG_LEVEL',
        'ESCAPE', 'TELEMETRY_ADMIN_ONLY', 'QUEUE_DRIVER', 'QUEUE_NAME',
        'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'TELEGRAM_LOG_LEVEL',
        'UI', 'ENQ_UI_FIX', 'TEMP', 'LOGS', 'LOCALES', 'FONTS', 'FONTS_TEMP',
        'MIGRATIONS', 'MIGRATIONS_BUNDLED', 'MIGRATIONS_CORE',
        'SEEDS', 'SEEDS_BUNDLED', 'USER_PLUGINS', 'FRAMEWORK_ROUTES',
        // ── Arrays / nested ───────────────────────────────────────────────────
        'THEME', 'PORTS', 'WS',
        'DB_CONFIG', 'REDIS', 'MEMCACHED', 'MUTEX', 'MAIL',
        'SESSION_CONFIG', 'JAR', 'CORS', 'RATE_LIMIT', 'QUEUE',
        'i18n', 'OAUTH', 'MONOPAY',
    ];

    // ── Setup ─────────────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        $package_dir = realpath(__DIR__ . '/../../../');
        if ($package_dir === false) {
            self::markTestSkipped('Could not resolve package directory');
        }

        $src_dir = realpath($package_dir . '/../src');
        if ($src_dir === false) {
            self::markTestSkipped('Could not resolve project src/ directory - skipping parity test');
        }

        defined('ATOMIC_DIR')       || define('ATOMIC_DIR', $src_dir);
        defined('ATOMIC_CONFIG')    || define('ATOMIC_CONFIG', ATOMIC_DIR . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);

        $framework = realpath($package_dir);
        self::assertNotFalse($framework, 'vendor/atomic/framework not found - run composer install first');

        defined('ATOMIC_FRAMEWORK') || define('ATOMIC_FRAMEWORK', $framework . DIRECTORY_SEPARATOR);
        defined('ATOMIC_ENGINE')    || define('ATOMIC_ENGINE', ATOMIC_FRAMEWORK . 'engine' . DIRECTORY_SEPARATOR);

        $env_file = ATOMIC_DIR . DIRECTORY_SEPARATOR . '.env';

        self::assertFileExists($env_file,    'Real .env file must exist for parity test');
        self::assertDirectoryExists(ATOMIC_CONFIG, 'Real config/ directory must exist for parity test');

        $f3 = \Base::instance();

        $get_hive = \Closure::bind(fn() => $this->hive, $f3, \Base::class);
        $set_hive = \Closure::bind(fn(array $h) => ($this->hive = $h), $f3, \Base::class);

        $clean_hive = $get_hive();

        // ── Run ENV loader, capture hive ──────────────────────────────────────
        ConfigLoader::init($f3, $env_file);
        self::$env_data = self::capture_hive($f3);

        // ── Full hive reset ───────────────────────────────────────────────────
        $set_hive($clean_hive);

        // ── Run PHP loader, capture hive ──────────────────────────────────────
        (new PhpConfigLoader($f3))->load();
        self::$php_data = self::capture_hive($f3);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function capture_hive(\Base $f3): array
    {
        $result = [];
        foreach (self::$all_keys as $key) {
            $result[$key] = $f3->get($key);
        }
        return $result;
    }

    // ── Parity tests (one per hive key) ───────────────────────────────────────

    public static function all_keys_provider(): array
    {
        return array_map(fn($k) => [$k], self::$all_keys);
    }

    #[DataProvider('all_keys_provider')]
    public function test_hive_key_parity(string $key): void
    {
        $env_val = self::$env_data[$key] ?? null;
        $php_val = self::$php_data[$key] ?? null;

        $this->assertSame(
            $env_val,
            $php_val,
            "Hive key '{$key}' differs between ENV and PHP loaders.\n"
            . '  ENV: ' . json_encode($env_val, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
            . '  PHP: ' . json_encode($php_val, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    // ── Sanity: loaders actually loaded real values (not empty stubs) ─────────

    public function test_app_name_is_non_empty(): void
    {
        $this->assertNotEmpty(self::$env_data['APP_NAME'], 'ENV APP_NAME must not be empty');
        $this->assertNotEmpty(self::$php_data['APP_NAME'], 'PHP APP_NAME must not be empty');
    }

    public function test_domain_is_non_empty(): void
    {
        $this->assertNotEmpty(self::$env_data['DOMAIN'], 'ENV DOMAIN must not be empty');
        $this->assertNotEmpty(self::$php_data['DOMAIN'], 'PHP DOMAIN must not be empty');
    }

    public function test_db_config_has_required_keys(): void
    {
        $required = ['driver', 'host', 'port', 'database', 'username', 'charset', 'collation',
                     'ATOMIC_DB_PREFIX', 'ATOMIC_DB_QUEUE_PREFIX'];
        foreach ($required as $k) {
            $this->assertArrayHasKey($k, self::$php_data['DB_CONFIG'], "DB_CONFIG missing '{$k}'");
        }
        $this->assertSame('atomic_',       self::$php_data['DB_CONFIG']['ATOMIC_DB_PREFIX']);
        $this->assertSame('atomic_queue_', self::$php_data['DB_CONFIG']['ATOMIC_DB_QUEUE_PREFIX']);
    }

    public function test_redis_has_constant_prefix_fields(): void
    {
        $this->assertSame('atomic.',         self::$php_data['REDIS']['ATOMIC_REDIS_PREFIX']);
        $this->assertSame('atomic.queue.',   self::$php_data['REDIS']['ATOMIC_REDIS_QUEUE_PREFIX']);
        $this->assertSame('atomic.session.', self::$php_data['REDIS']['ATOMIC_REDIS_SESSION_PREFIX']);
    }

    public function test_session_kill_on_suspect_is_bool(): void
    {
        $this->assertIsBool(self::$env_data['SESSION_CONFIG']['kill_on_suspect']);
        $this->assertIsBool(self::$php_data['SESSION_CONFIG']['kill_on_suspect']);
    }

    public function test_cors_credentials_is_bool(): void
    {
        $this->assertIsBool(self::$env_data['CORS']['credentials']);
        $this->assertIsBool(self::$php_data['CORS']['credentials']);
    }

    public function test_rate_limit_has_register_and_login(): void
    {
        $this->assertArrayHasKey('register', self::$php_data['RATE_LIMIT']);
        $this->assertArrayHasKey('login',    self::$php_data['RATE_LIMIT']);
    }

    public function test_queue_has_database_and_redis_drivers(): void
    {
        $this->assertArrayHasKey('database', self::$php_data['QUEUE']);
        $this->assertArrayHasKey('redis',    self::$php_data['QUEUE']);
    }

    public function test_i18n_languages_is_array_with_values(): void
    {
        $this->assertIsArray(self::$php_data['i18n']['languages']);
        $this->assertNotEmpty(self::$php_data['i18n']['languages']);
    }

    public function test_jar_stable_cookie_fields(): void
    {
        $jar = self::$php_data['JAR'];
        foreach (['lifetime', 'path', 'secure', 'httponly', 'samesite'] as $k) {
            $this->assertArrayHasKey($k, $jar, "JAR missing key '{$k}'");
        }
        $this->assertIsBool($jar['secure']);
        $this->assertIsBool($jar['httponly']);
        $this->assertIsInt($jar['lifetime']);
        $this->assertNotEmpty($jar['path']);
    }

    // ── build_cache_string unit tests ─────────────────────────────────────────

    #[DataProvider('cache_string_provider')]
    public function test_build_cache_string(
        string $driver,
        string $folder,
        string $server,
        string $port,
        string $password,
        string $login,
        string|false $expected
    ): void {
        $loader = new class(\Base::instance()) extends ConfigLoader {
            public function public_build_cache_string(
                string $driver, string $folder, string $server,
                string $port, string $password, string $login
            ): string|false {
                return $this->build_cache_string($driver, $folder, $server, $port, $password, $login);
            }
        };

        $this->assertSame($expected, $loader->public_build_cache_string($driver, $folder, $server, $port, $password, $login));
    }

    public static function cache_string_provider(): array
    {
        return [
            'folder driver'            => ['folder',    '/tmp/cache/', '',          '',     '',       '',       'folder=/tmp/cache/'],
            'redis no auth'            => ['redis',     '',            '127.0.0.1', '6379', '',       '',       'redis=127.0.0.1:6379'],
            'redis password only'      => ['redis',     '',            '127.0.0.1', '6379', 'secret', '',       'redis=127.0.0.1:6379?auth=secret'],
            'redis login and password' => ['redis',     '',            '127.0.0.1', '6379', 'pass',   'user',   'redis=127.0.0.1:6379?auth=user:pass'],
            'memcache driver'          => ['memcache',  '',            'localhost',  '11211','',       '',       'memcache=localhost:11211'],
            'memcached driver'         => ['memcached', '',            'localhost',  '11211','',       '',       'memcache=localhost:11211'],
            'apc driver'               => ['apc',       '',            '',          '',     '',       '',       'apc'],
            'xcache driver'            => ['xcache',    '',            '',          '',     '',       '',       'xcache'],
            'wincache driver'          => ['wincache',  '',            '',          '',     '',       '',       'wincache'],
            'unknown driver'           => ['none',      '',            '',          '',     '',       '',       false],
            'false string driver'      => ['false',     '',            '',          '',     '',       '',       false],
        ];
    }

    // ── parse_env unit tests ──────────────────────────────────────────────────

    public function test_parse_env_reads_key_value_pairs(): void
    {
        $file = sys_get_temp_dir() . '/test_parse_env_' . uniqid() . '.env';
        file_put_contents($file, "FOO=bar\nBAZ=qux\n");

        $loader = new class(\Base::instance()) extends ConfigLoader {
            public function public_parse_env(string $file): array { return $this->parse_env($file); }
        };

        $result = $loader->public_parse_env($file);
        unlink($file);

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    public function test_parse_env_skips_comments_and_blank_lines(): void
    {
        $file = sys_get_temp_dir() . '/test_parse_env_comments_' . uniqid() . '.env';
        file_put_contents($file, "# comment\n\nFOO=bar\n# another\nBAZ=qux\n");

        $loader = new class(\Base::instance()) extends ConfigLoader {
            public function public_parse_env(string $file): array { return $this->parse_env($file); }
        };

        $result = $loader->public_parse_env($file);
        unlink($file);

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    public function test_parse_env_strips_inline_comments(): void
    {
        $file = sys_get_temp_dir() . '/test_parse_env_inline_' . uniqid() . '.env';
        file_put_contents($file, "FOO=bar # inline comment\nBAZ=qux\n");

        $loader = new class(\Base::instance()) extends ConfigLoader {
            public function public_parse_env(string $file): array { return $this->parse_env($file); }
        };

        $result = $loader->public_parse_env($file);
        unlink($file);

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $result);
    }

    public function test_parse_env_returns_empty_for_missing_file(): void
    {
        $loader = new class(\Base::instance()) extends ConfigLoader {
            public function public_parse_env(string $file): array { return $this->parse_env($file); }
        };

        $this->assertSame([], $loader->public_parse_env('/nonexistent/path/.env'));
    }

    // ── PhpConfigLoader cfg / cfg_nested unit tests ───────────────────────────

    public function test_php_loader_cfg_returns_value(): void
    {
        $loader = new class(\Base::instance()) extends PhpConfigLoader {
            public function set_configs(array $c): void { $this->configs = $c; }
            public function public_cfg(string $file, string $key, mixed $default = null): mixed
            {
                return $this->cfg($file, $key, $default);
            }
        };
        $loader->set_configs(['app' => ['name' => 'MyApp', 'debug' => true]]);

        $this->assertSame('MyApp', $loader->public_cfg('app', 'name'));
        $this->assertSame(true,    $loader->public_cfg('app', 'debug'));
        $this->assertSame('def',   $loader->public_cfg('app', 'missing', 'def'));
        $this->assertNull($loader->public_cfg('nonexistent', 'key'));
    }

    public function test_php_loader_cfg_nested_resolves_dot_path(): void
    {
        $loader = new class(\Base::instance()) extends PhpConfigLoader {
            public function set_configs(array $c): void { $this->configs = $c; }
            public function public_cfg_nested(string $file, string $dot_path, mixed $default = null): mixed
            {
                return $this->cfg_nested($file, $dot_path, $default);
            }
        };
        $loader->set_configs([
            'database' => [
                'connections' => [
                    'mysql' => ['host' => 'db.example.com', 'port' => 3306],
                ],
            ],
        ]);

        $this->assertSame(
            ['host' => 'db.example.com', 'port' => 3306],
            $loader->public_cfg_nested('database', 'connections.mysql')
        );
        $this->assertSame(
            'db.example.com',
            $loader->public_cfg_nested('database', 'connections.mysql.host')
        );
        $this->assertSame(
            'fallback',
            $loader->public_cfg_nested('database', 'connections.mysql.missing', 'fallback')
        );
    }
}
