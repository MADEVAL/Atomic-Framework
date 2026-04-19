<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private ConfigLoader $loader;
    private \Base $f3;
    private string $envFile;

    protected function setUp(): void
    {
        $this->f3 = \Base::instance();
        $this->loader = new ConfigLoader($this->f3);
        $this->envFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_' . uniqid() . '.env';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->envFile)) {
            @unlink($this->envFile);
        }
    }

    public function test_parse_env_basic(): void
    {
        file_put_contents($this->envFile, "APP_NAME=TestApp\nAPP_KEY=secret123\nDOMAIN=https://test.com/\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->envFile);
        $this->assertSame('TestApp', $data['APP_NAME']);
        $this->assertSame('secret123', $data['APP_KEY']);
        $this->assertSame('https://test.com/', $data['DOMAIN']);
    }

    public function test_parse_env_skips_comments(): void
    {
        file_put_contents($this->envFile, "# This is a comment\nKEY=value\n# Another comment\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->envFile);
        $this->assertCount(1, $data);
        $this->assertSame('value', $data['KEY']);
    }

    public function test_parse_env_skips_empty_lines(): void
    {
        file_put_contents($this->envFile, "\n\nKEY=val\n\n\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->envFile);
        $this->assertCount(1, $data);
    }

    public function test_parse_env_strips_inline_comments(): void
    {
        file_put_contents($this->envFile, "KEY=value #inline comment\n");
        $ref = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $data = $ref->invoke($this->loader, $this->envFile);
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
        file_put_contents($this->envFile, "EXISTS=yes\n");
        $ref_parse = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $env = $ref_parse->invoke($this->loader, $this->envFile);

        $refProp = new \ReflectionProperty(ConfigLoader::class, 'env');
        $refProp->setValue($this->loader, $env);

        $ref = new \ReflectionMethod(ConfigLoader::class, 'get_env');
        $this->assertSame('yes', $ref->invoke($this->loader, 'EXISTS'));
        $this->assertSame('fallback', $ref->invoke($this->loader, 'MISSING', 'fallback'));
        $this->assertNull($ref->invoke($this->loader, 'MISSING'));
    }

    public function test_load_sets_hive_values(): void
    {
        file_put_contents($this->envFile, implode("\n", [
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
            'CACHE_DRIVER=folder',
            'CACHE_PATH=tmp/',
            'DEBUG_MODE=true',
            'DEBUG_LEVEL=debug',
        ]));

        $this->loader->load($this->envFile);

        $this->assertSame('LoadTest', $this->f3->get('APP_NAME'));
        $this->assertSame('my-key', $this->f3->get('APP_KEY'));
        $this->assertSame('test-uuid', $this->f3->get('APP_UUID'));

        $dbConfig = $this->f3->get('DB_CONFIG');
        $this->assertSame('mysql', $dbConfig['driver']);
        $this->assertSame('testdb', $dbConfig['db']);
        $this->assertSame('root', $dbConfig['username']);
    }

    public function test_load_sets_redis_config(): void
    {
        file_put_contents($this->envFile, implode("\n", [
            'REDIS_HOST=10.0.0.1',
            'REDIS_PORT=6380',
            'REDIS_PASSWORD=secret',
            'REDIS_DB=2',
            'CACHE_PREFIX=atomic.cache.',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

        $redis = $this->f3->get('REDIS');
        $this->assertSame('10.0.0.1', $redis['host']);
        $this->assertSame('6380', $redis['port']);
        $this->assertSame('secret', $redis['password']);
        $this->assertSame(2, $redis['db']);
        $this->assertSame('atomic.cache.', $redis['ATOMIC_REDIS_PREFIX']);
    }

    public function test_load_sets_mail_config(): void
    {
        file_put_contents($this->envFile, implode("\n", [
            'MAIL_DRIVER=smtp',
            'MAIL_HOST=mail.test.com',
            'MAIL_PORT=465',
            'MAIL_FROM_ADDRESS=test@test.com',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

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
        file_put_contents($this->envFile, implode("\n", [
            'SESSION_DRIVER=redis',
            'SESSION_LIFETIME=7200',
            'SESSION_COOKIE=my_session',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

        $session = $this->f3->get('SESSION_CONFIG');
        $this->assertSame('redis', $session['driver']);
        $this->assertSame('my_session', $session['cookie']);
    }

    public function test_load_sets_cors(): void
    {
        file_put_contents($this->envFile, implode("\n", [
            'CORS_ORIGIN=https://allowed.com',
            'CORS_CREDENTIALS=true',
            'CORS_TTL=3600',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

        $cors = $this->f3->get('CORS');
        $this->assertSame('https://allowed.com', $cors['origin']);
        $this->assertTrue($cors['credentials']);
        $this->assertSame(3600, $cors['ttl']);
    }

    public function test_load_sets_i18n(): void
    {
        file_put_contents($this->envFile, implode("\n", [
            'I18N_LANGUAGES=en,fr,de',
            'I18N_DEFAULT=fr',
            'I18N_URL_MODE=prefix',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

        $i18n = $this->f3->get('i18n');
        $this->assertSame(['en', 'fr', 'de'], $i18n['languages']);
        $this->assertSame('fr', $i18n['default']);
    }

    public function test_build_queue_config(): void
    {
        file_put_contents($this->envFile, implode("\n", [
            'QUEUE_DB_DEFAULT_DELAY=5',
            'QUEUE_DB_DEFAULT_MAX_ATTEMPTS=3',
            'QUEUE_DB_EMAIL_DELAY=10',
            'QUEUE_DB_EMAIL_MAX_ATTEMPTS=5',
            'CACHE_DRIVER=folder',
        ]));

        $this->loader->load($this->envFile);

        $queue = $this->f3->get('QUEUE');
        $this->assertArrayHasKey('db', $queue);
        $dbQueues = $queue['db']['queues'];
        $this->assertArrayHasKey('default', $dbQueues);
        $this->assertSame(5, $dbQueues['default']['delay']);
        $this->assertArrayHasKey('email', $dbQueues);
        $this->assertSame(10, $dbQueues['email']['delay']);
        $this->assertSame(5, $dbQueues['email']['max_attempts']);
    }
}
