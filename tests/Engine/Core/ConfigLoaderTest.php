<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Auth\ConfigUserStore;
use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\PhpConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private ConfigLoader $loader;
    private \Base $f3;
    private string $env_file;

    protected function setUp(): void
    {
        $this->f3 = \Base::instance();
        $this->loader = new ConfigLoader($this->f3);
        $this->env_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->env_file)) {
            @unlink($this->env_file);
        }
    }

    public function test_parse_env_basic(): void
    {
        file_put_contents($this->env_file, "APP_NAME=TestApp\nAPP_KEY=secret123\nDOMAIN=https://test.com/\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->env_file);
        $this->assertSame('TestApp', $data['APP_NAME']);
        $this->assertSame('secret123', $data['APP_KEY']);
        $this->assertSame('https://test.com/', $data['DOMAIN']);
    }

    public function test_parse_env_skips_comments(): void
    {
        file_put_contents($this->env_file, "# This is a comment\nKEY=value\n# Another comment\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->env_file);
        $this->assertCount(1, $data);
        $this->assertSame('value', $data['KEY']);
    }

    public function test_parse_env_skips_empty_lines(): void
    {
        file_put_contents($this->env_file, "\n\nKEY=val\n\n\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->env_file);
        $this->assertCount(1, $data);
    }

    public function test_parse_env_strips_inline_comments(): void
    {
        file_put_contents($this->env_file, "KEY=value #inline comment\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->env_file);
        $this->assertSame('value', $data['KEY']);
    }

    public function test_parse_env_nonexistent_file(): void
    {
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, '/nonexistent/path/.env');
        $this->assertSame([], $data);
    }

    public function test_get_env_default(): void
    {
        file_put_contents($this->env_file, "EXISTS=yes\n");
        $ref_parse = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $env = $ref_parse->invoke($this->loader, $this->env_file);

        $ref_prop = new \ReflectionProperty(ConfigLoader::class, 'env');
        $ref_prop->setValue($this->loader, $env);

        $ref = new \ReflectionMethod(ConfigLoader::class, 'get_env');
        $this->assertSame('yes', $ref->invoke($this->loader, 'EXISTS'));
        $this->assertSame('fallback', $ref->invoke($this->loader, 'MISSING', 'fallback'));
        $this->assertNull($ref->invoke($this->loader, 'MISSING'));
    }

    public function test_load_sets_hive_values(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'APP_NAME=LoadTest',
            'APP_KEY=my-key',
            'APP_UUID=test-uuid',
            'DOMAIN=https://load.test/',
            'LANGUAGE=en',
            'DB_DRIVER=mysql',
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DB=testdb',
            'DB_USERNAME=root',
            'DB_PASSWORD=secret',
            'DB_PREFIX=custom_',
            'CACHE_DRIVER=folder',
            'CACHE_PATH=tmp/',
            'DEBUG_MODE=true',
            'DEBUG_LEVEL=debug',
        ]));

        $this->loader->load($this->env_file);

        $this->assertSame('LoadTest', $this->f3->get('APP_NAME'));
        $this->assertSame('my-key', $this->f3->get('APP_KEY'));
        $this->assertSame('test-uuid', $this->f3->get('APP_UUID'));

        $db_config = $this->f3->get('DB_CONFIG');
        $this->assertSame('mysql', $db_config['driver']);
        $this->assertSame('testdb', $db_config['db']);
        $this->assertSame('root', $db_config['username']);
        $this->assertSame('custom_', $db_config['prefix']);
    }

    public function test_load_sets_telemetry_access_roles_from_env(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'TELEMETRY_ACCESS_MODE=auth',
            'TELEMETRY_ACCESS_ALLOWED_ROLES=admin, support, viewer',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $this->assertSame('auth', $this->f3->get('TELEMETRY_ACCESS_MODE'));
        $this->assertSame(['admin', 'support', 'viewer'], $this->f3->get('TELEMETRY_ACCESS_ALLOWED_ROLES'));
    }

    public function test_loader_reads_access_guards_from_storage(): void
    {
        $store = new ConfigUserStore(ATOMIC_DIR);
        $path = $store->path();
        $backup = is_file($path) ? (string)file_get_contents($path) : null;

        try {
            $store->upsert_user(
                'telemetry',
                'viewer',
                '11111111-1111-4111-8111-111111111111',
                password_hash('secret', PASSWORD_DEFAULT),
                ['telemetry.viewer', 'telemetry.admin'],
            );

            $this->loader->load($this->env_file);

            $user = $this->f3->get('ACCESS.guards.telemetry.users.viewer');
            $this->assertSame('11111111-1111-4111-8111-111111111111', $user['id']);
            $this->assertSame('viewer', $user['username']);
            $this->assertSame(['telemetry.viewer', 'telemetry.admin'], $user['roles']);
        } finally {
            if ($backup === null) {
                @unlink($path);
            } else {
                @file_put_contents($path, $backup);
            }
        }
    }

    public function test_load_sets_redis_config(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'REDIS_HOST=10.0.0.1',
            'REDIS_PORT=6380',
            'REDIS_PASSWORD=secret',
            'REDIS_DB=2',
            'CACHE_PREFIX=atomic.cache.',
            'REDIS_PREFIX=atomic.redis.',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $redis = $this->f3->get('REDIS');
        $this->assertSame('10.0.0.1', $redis['host']);
        $this->assertSame('6380', $redis['port']);
        $this->assertSame('secret', $redis['password']);
        $this->assertSame(2, $redis['db']);
        $this->assertSame('atomic.redis.', $redis['prefix']);
    }

    public function test_load_sets_mail_config(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'MAIL_DRIVER=smtp',
            'MAIL_HOST=mail.test.com',
            'MAIL_PORT=465',
            'MAIL_FROM_ADDRESS=test@test.com',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $mail = $this->f3->get('MAIL');
        $this->assertSame('smtp', $mail['driver']);
        $this->assertSame('mail.test.com', $mail['host']);
        $this->assertSame('test@test.com', $mail['from_address']);

        $this->assertSame('mail.test.com', $this->f3->get('MAILER.smtp.host'));
        $this->assertSame(465, $this->f3->get('MAILER.smtp.port'));
        $this->assertSame('test@test.com', $this->f3->get('MAILER.from_mail'));
    }

    public function test_load_sets_session_config(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'SESSION_DRIVER=redis',
            'SESSION_LIFETIME=7200',
            'SESSION_COOKIE=my_session',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $session = $this->f3->get('SESSION_CONFIG');
        $this->assertSame('redis', $session['driver']);
        $this->assertSame('my_session', $session['cookie']);
    }

    public function test_load_sets_cors(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'CORS_ORIGIN=https://allowed.com',
            'CORS_CREDENTIALS=true',
            'CORS_TTL=3600',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $cors = $this->f3->get('CORS');
        $this->assertSame('https://allowed.com', $cors['origin']);
        $this->assertTrue($cors['credentials']);
        $this->assertSame(3600, $cors['ttl']);
    }

    public function test_load_sets_i18n(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'I18N_LANGUAGES=en,fr,de',
            'I18N_DEFAULT=fr',
            'I18N_URL_MODE=prefix',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $i18n = $this->f3->get('i18n');
        $this->assertSame(['en', 'fr', 'de'], $i18n['languages']);
        $this->assertSame('fr', $i18n['default']);
    }

    public function test_build_queue_config(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'QUEUE_DB_DEFAULT_DELAY=5',
            'QUEUE_DB_DEFAULT_MAX_ATTEMPTS=3',
            'QUEUE_DB_EMAIL_DELAY=10',
            'QUEUE_DB_EMAIL_MAX_ATTEMPTS=5',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $queue = $this->f3->get('QUEUE');
        $this->assertArrayHasKey('db', $queue);
        $db_queues = $queue['db']['queues'];
        $this->assertArrayHasKey('default', $db_queues);
        $this->assertSame(5, $db_queues['default']['delay']);
        $this->assertArrayHasKey('email', $db_queues);
        $this->assertSame(10, $db_queues['email']['delay']);
        $this->assertSame(5, $db_queues['email']['max_attempts']);
    }

    public function test_env_custom_config_maps_config_prefixed_scalars_only(): void
    {
        file_put_contents($this->env_file, implode("\n", [
            'CONFIG_FEATURES_MONTHLY_QUOTA=1000',
            'CONFIG_FEATURES_ENDPOINTS_ENABLED=true',
            'CONFIG_FEATURES_SAMPLE_RATIO=0.7',
            'CONFIG_FEATURES_PROVIDERS=primary, secondary,backup',
            'CONFIG_BILLING_MODE=live',
            'DB_CONFIG_SHOULD_NOT_LOAD=unsafe',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->env_file);

        $this->assertSame([
            'features' => [
                'monthly_quota' => 1000,
                'endpoints_enabled' => true,
                'sample_ratio' => 0.7,
                'providers' => ['primary', 'secondary', 'backup'],
            ],
            'billing' => [
                'mode' => 'live',
            ],
        ], $this->f3->get('CONFIG'));

        $this->assertNull($this->f3->get('DB_CONFIG_SHOULD_NOT_LOAD'));
    }

    public function test_php_custom_configs_load_under_config_namespace_only(): void
    {
        $config_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_config_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($config_dir);
        file_put_contents($config_dir . 'feature_flags.php', "<?php\nreturn ['enabled' => true, 'quota' => 1000, 'providers' => ['primary']];\n");
        file_put_contents($config_dir . 'billing.php', "<?php\nreturn ['mode' => 'test'];\n");
        file_put_contents($config_dir . 'database.php', "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite']]];\n");
        file_put_contents($config_dir . 'index.php', "<?php\nreturn ['ignored' => true];\n");
        file_put_contents($config_dir . 'bad.php', "<?php\nreturn 'not-an-array';\n");

        try {
            $loader = new class($this->f3) extends PhpConfigLoader {
                public function set_config_path(string $path): void
                {
                    $this->config_path = $path;
                }
            };
            $loader->set_config_path($config_dir);
            $configs = $loader->load();

            $this->assertSame([
                'billing' => ['mode' => 'test'],
                'feature_flags' => ['enabled' => true, 'quota' => 1000, 'providers' => ['primary']],
            ], $this->f3->get('CONFIG'));
            $this->assertSame($this->f3->get('CONFIG'), $configs['_custom']);
            $this->assertArrayNotHasKey('bad', $this->f3->get('CONFIG'));
            $this->assertArrayNotHasKey('index', $this->f3->get('CONFIG'));
            $this->assertArrayNotHasKey('database', $this->f3->get('CONFIG'));
        } finally {
            foreach (glob($config_dir . '*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($config_dir);
        }
    }
}
