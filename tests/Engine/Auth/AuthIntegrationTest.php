<?php
declare(strict_types=1);

namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\AppContextAdapter;
use Engine\Atomic\Auth\Adapters\BcryptHasherAdapter;
use Engine\Atomic\Auth\Adapters\IdValidatorAdapter;
use Engine\Atomic\Auth\Adapters\LogAdapter;
use Engine\Atomic\Auth\Adapters\MetaStorageAdapter;
use Engine\Atomic\Auth\Adapters\PhpSessionAdapter;
use Engine\Atomic\Auth\Adapters\SessionDriverFactoryAdapter;
use Engine\Atomic\Auth\Adapters\SessionManagerAdapter;
use Engine\Atomic\Auth\Adapters\SystemClockAdapter;
use Engine\Atomic\Auth\Adapters\TransientCacheAdapter;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Auth\Services\AuthService;
use Engine\Atomic\Auth\Services\SessionService;
use Engine\Atomic\Core\App;
use Engine\Atomic\Enums\Role;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthIntegrationTest extends TestCase
{
    private const IP = '203.0.113.10';
    private const AGENT = 'PHPUnit Integration Agent/1.0';

    private ?\PDO $pdo = null;
    private ?string $db_prefix = null;
    private ?string $redis_prefix = null;
    private ?\Redis $redis = null;
    private ?SessionService $session_service = null;
    private ?AuthService $auth_service = null;

    protected function tearDown(): void
    {
        if (\session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @\session_destroy();
            @\session_write_close();
        }

        if ($this->redis !== null && $this->redis_prefix !== null) {
            $keys = $this->redis->keys($this->redis_prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
        }

        if ($this->pdo !== null && $this->db_prefix !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . 'sessions'));
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quote_identifier($this->db_prefix . 'meta'));
        }

        $this->pdo = null;
    }

    public function test_db_driver_persists_session_and_meta_to_real_database(): void
    {
        $this->boot_environment('db');

        $user_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->auth_service->login_by_id($user_uuid);

        $session_id = \session_id();
        self::assertNotSame('', $session_id);
        self::assertSame($user_uuid, $_SESSION['user_uuid'] ?? null);

        \session_write_close();

        $session_row = $this->fetch_session_row($session_id);
        self::assertNotNull($session_row);
        self::assertStringContainsString('user_uuid', (string) $session_row['data']);
        self::assertStringContainsString($user_uuid, (string) $session_row['data']);
        self::assertSame(self::IP, $session_row['ip']);
        self::assertSame(self::AGENT, $session_row['agent']);

        $meta_row = $this->fetch_meta_row($user_uuid, $session_id);
        self::assertNotNull($meta_row);

        $meta = \json_decode((string) $meta_row['value'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::IP, $meta['ip'] ?? null);
        self::assertSame(self::AGENT, $meta['user_agent'] ?? null);
        self::assertSame('pc', $meta['device_type'] ?? null);
    }

    public function test_redis_driver_persists_session_key_to_real_redis(): void
    {
        $this->boot_environment('redis');

        $user_uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $this->auth_service->login_by_id($user_uuid);

        $session_id = \session_id();
        self::assertNotSame('', $session_id);
        self::assertSame($user_uuid, $_SESSION['user_uuid'] ?? null);

        \session_write_close();

        $redis_key = $this->redis_prefix . $session_id;
        $payload = $this->redis->get($redis_key);

        self::assertIsString($payload);
        $session = \json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($session_id, $session['session_id'] ?? null);
        self::assertSame(self::IP, $session['ip'] ?? null);
        self::assertSame(self::AGENT, $session['agent'] ?? null);
        self::assertStringContainsString('user_uuid', (string) ($session['data'] ?? ''));
        self::assertStringContainsString($user_uuid, (string) ($session['data'] ?? ''));

        $meta_row = $this->fetch_meta_row($user_uuid, $session_id);
        self::assertNotNull($meta_row);
    }

    public function test_full_impersonation_cycle_persists_real_meta_and_rotates_session_ids(): void
    {
        $this->boot_environment('db');

        $admin_uuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $target_uuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

        $this->auth_service->set_user_provider(new ArrayUserProvider([
            $admin_uuid => new RoleUser($admin_uuid, [Role::ADMIN->value]),
            $target_uuid => new RoleUser($target_uuid, [Role::SELLER->value]),
        ]));

        $this->auth_service->login_by_id($admin_uuid);
        $login_session_id = \session_id();

        self::assertTrue($this->auth_service->impersonate_user($target_uuid));
        $impersonated_session_id = \session_id();

        self::assertNotSame($login_session_id, $impersonated_session_id);
        self::assertSame($target_uuid, $_SESSION['user_uuid'] ?? null);
        self::assertSame($admin_uuid, $_SESSION['admin_uuid'] ?? null);

        \session_write_close();

        $target_meta_row = $this->fetch_meta_row($target_uuid, $impersonated_session_id);
        self::assertNotNull($target_meta_row);

        $target_meta = \json_decode((string) $target_meta_row['value'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($admin_uuid, $target_meta['impersonated_by'] ?? null);

        $impersonated_session_row = $this->fetch_session_row($impersonated_session_id);
        self::assertNotNull($impersonated_session_row);
        self::assertStringContainsString($target_uuid, (string) $impersonated_session_row['data']);
        self::assertStringContainsString($admin_uuid, (string) $impersonated_session_row['data']);

        $this->reopen_session($impersonated_session_id);

        self::assertTrue($this->auth_service->stop_impersonation());
        $restored_session_id = \session_id();

        self::assertNotSame($impersonated_session_id, $restored_session_id);
        self::assertSame($admin_uuid, $_SESSION['user_uuid'] ?? null);
        self::assertNull($_SESSION['admin_uuid'] ?? null);

        \session_write_close();

        $restored_session_row = $this->fetch_session_row($restored_session_id);
        self::assertNotNull($restored_session_row);
        self::assertStringContainsString($admin_uuid, (string) $restored_session_row['data']);

        $admin_meta_row = $this->fetch_meta_row($admin_uuid, $restored_session_id);
        self::assertNotNull($admin_meta_row);

        $target_meta_still_exists = $this->fetch_meta_row($target_uuid, $impersonated_session_id);
        self::assertNotNull($target_meta_still_exists);
    }

    private function boot_environment(string $driver): void
    {
        $_COOKIE = [];
        $_SESSION = [];

        if (!\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('ext-pdo_mysql not loaded - cannot run AuthIntegration tests requiring MySQL.');
        }

        $this->db_prefix = 'atomic_test_' . \bin2hex(\random_bytes(4)) . '_';
        $this->redis_prefix = 'atomic_auth_test:' . \bin2hex(\random_bytes(4)) . ':';

        $db_host = $this->env_value('DB_HOST', '127.0.0.1');
        $db_port = $this->env_value('DB_PORT', '3306');
        $db_name = $this->env_value('DB_DB', 'atomic_test');
        $db_user = $this->env_value('DB_USERNAME', 'atomic_test_user');
        $db_password = $this->env_value('DB_PASSWORD', 'atomic_test_pass');
        $db_charset = $this->env_value('DB_CHARSET', 'utf8mb4');
        $db_collation = $this->env_value('DB_COLLATION', 'utf8mb4_general_ci');
        $redis_host = $this->env_value('REDIS_HOST', '127.0.0.1');
        $redis_port = (int) $this->env_value('REDIS_PORT', '6379');

        [$pdo, $effective_db_host, $db_error] = $this->connect_pdo_with_fallback(
            $db_host,
            $db_port,
            $db_name,
            $db_user,
            $db_password,
            $db_charset,
        );

        if ($pdo === null) {
            self::markTestSkipped('MySQL connection unavailable for AuthIntegration: ' . $db_error);
        }

        $this->pdo = $pdo;
        $db_host = $effective_db_host;
        $dsn = \sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db_host,
            $db_port,
            $db_name,
            $db_charset,
        );

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->create_schema($this->pdo, $this->db_prefix);

        $base = \Base::instance();
        $base->clear('SESSION');
        $base->set('IP', self::IP);
        $base->set('AGENT', self::AGENT);
        $base->set('HEADERS', ['User-Agent' => self::AGENT]);
        $base->set('HEADERS.User-Agent', self::AGENT);
        $base->set('DB_CONFIG', [
            'driver' => 'mysql',
            'host' => $db_host,
            'port' => $db_port,
            'db' => $db_name,
            'username' => $db_user,
            'password' => $db_password,
            'unix_socket' => '',
            'charset' => $db_charset,
            'collation' => $db_collation,
            'prefix' => $this->db_prefix,
        ]);
        $base->set('DB', new \DB\SQL($dsn, $db_user, $db_password));
        $base->set('REDIS', [
            'host' => $redis_host,
            'port' => $redis_port,
            'password' => $this->env_value('REDIS_PASSWORD', ''),
            'db' => (int) $this->env_value('REDIS_DB', '0'),
            'prefix' => $this->redis_prefix,
        ]);
        $base->set('SESSION_CONFIG', [
            'driver' => $driver,
            'lifetime' => 7200,
            'cookie' => 'atomic_session_' . \bin2hex(\random_bytes(4)),
            'kill_on_suspect' => false,
        ]);

        App::instance($base);

        if (extension_loaded('redis')) {
            [$redis, $effective_redis_host, $redis_error] = $this->connect_redis_with_fallback($redis_host, $redis_port);
            if ($redis === null) {
                self::markTestSkipped('Redis connection unavailable for AuthIntegration: ' . $redis_error);
            }

            $this->redis = $redis;
            $redis_host = $effective_redis_host;
        } elseif ($driver === 'redis') {
            self::markTestSkipped('ext-redis not loaded - cannot run Redis driver tests.');
        }

        $app = new AppContextAdapter();
        $this->session_service = new SessionService(
            $app,
            new PhpSessionAdapter(),
            new SessionDriverFactoryAdapter($app),
            new SystemClockAdapter(),
            new IdValidatorAdapter(),
            new LogAdapter(),
        );
        $this->session_service->init();

        $this->auth_service = new AuthService(
            $app,
            $this->session_service,
            new MetaStorageAdapter(),
            new TransientCacheAdapter(),
            new LogAdapter(),
            new SystemClockAdapter(),
            new PhpSessionAdapter(),
            new BcryptHasherAdapter(),
            new SessionManagerAdapter(),
        );
    }

    private function create_schema(\PDO $pdo, string $prefix): void
    {
        $pdo->exec(
            'CREATE TABLE ' . $this->quote_identifier($prefix . 'meta') . ' (' .
            '_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
            'uuid VARCHAR(128) NOT NULL, ' .
            '`key` VARCHAR(128) NOT NULL, ' .
            '`value` LONGTEXT NULL, ' .
            'created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE TABLE ' . $this->quote_identifier($prefix . 'sessions') . ' (' .
            '_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ' .
            'session_id VARCHAR(256) NOT NULL, ' .
            'data LONGTEXT NULL, ' .
            'ip VARCHAR(128) NULL, ' .
            'agent VARCHAR(512) NULL, ' .
            'stamp INT NULL' .
            ') ENGINE=InnoDB'
        );
        $pdo->exec(
            'CREATE INDEX ' . $this->quote_identifier($prefix . 'sessions_session_id_idx') .
            ' ON ' . $this->quote_identifier($prefix . 'sessions') . ' (session_id)'
        );
        $pdo->exec(
            'CREATE INDEX ' . $this->quote_identifier($prefix . 'meta_uuid_key_idx') .
            ' ON ' . $this->quote_identifier($prefix . 'meta') . ' (uuid, `key`)'
        );
    }

    private function fetch_session_row(string $session_id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT session_id, data, ip, agent, stamp FROM ' . $this->quote_identifier($this->db_prefix . 'sessions') .
            ' WHERE session_id = :session_id LIMIT 1'
        );
        $statement->execute(['session_id' => $session_id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function fetch_meta_row(string $uuid, string $session_id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT uuid, `key`, `value` FROM ' . $this->quote_identifier($this->db_prefix . 'meta') .
            ' WHERE uuid = :uuid AND `key` = :meta_key LIMIT 1'
        );
        $statement->execute([
            'uuid' => $uuid,
            'meta_key' => 'auth_session_' . $session_id,
        ]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function reopen_session(string $session_id): void
    {
        \session_id($session_id);
        \session_start();

        $base = \Base::instance();
        foreach ($_SESSION as $key => $value) {
            $base->set('SESSION.' . $key, $value);
        }
    }

    private function quote_identifier(string $identifier): string
    {
        return '`' . \str_replace('`', '``', $identifier) . '`';
    }

    private function env_value(string $key, string $default): string
    {
        $value = \getenv($key);
        if (\is_string($value) && $value !== '') {
            return $value;
        }

        static $env = null;
        if ($env === null) {
            $env = [];
            $candidate_paths = [];

            if (\defined('ATOMIC_ENV') && \is_string(ATOMIC_ENV) && ATOMIC_ENV !== '') {
                $candidate_paths[] = ATOMIC_ENV;
            }

            $candidate_paths[] = \dirname(__DIR__, 4) . '/.env';

            foreach ($candidate_paths as $env_path) {
                if (!\is_file($env_path)) {
                    continue;
                }

                foreach (\file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = \trim($line);
                    if ($line === '' || $line[0] === '#' || !\str_contains($line, '=')) {
                        continue;
                    }

                    [$env_key, $env_value] = \explode('=', $line, 2);
                    $env[\trim($env_key)] = \trim(\explode('#', $env_value, 2)[0]);
                }

                break;
            }
        }

        return $env[$key] ?? $default;
    }

    private function connect_pdo_with_fallback(
        string $db_host,
        string $db_port,
        string $db_name,
        string $db_user,
        string $db_password,
        string $db_charset,
    ): array {
        $hosts = [$db_host];
        if ($this->is_loopback_host($db_host)) {
            $hosts[] = 'mysql';
        }
        $hosts = array_values(array_unique($hosts));

        $last_error = 'unknown database connection error';
        foreach ($hosts as $host) {
            $dsn = \sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $host,
                $db_port,
                $db_name,
                $db_charset,
            );

            try {
                $pdo = new \PDO($dsn, $db_user, $db_password);
                return [$pdo, $host, null];
            } catch (\Throwable $e) {
                $last_error = $e->getMessage();
            }
        }

        return [null, $db_host, $last_error];
    }

    private function connect_redis_with_fallback(string $redis_host, int $redis_port): array
    {
        $hosts = [$redis_host];
        if ($this->is_loopback_host($redis_host)) {
            $hosts[] = 'redis';
        }
        $hosts = array_values(array_unique($hosts));

        $last_error = 'unknown redis connection error';
        foreach ($hosts as $host) {
            $redis = new \Redis();
            try {
                if ($redis->connect($host, $redis_port, 1.0)) {
                    return [$redis, $host, null];
                }
                $last_error = 'connect() returned false for ' . $host . ':' . $redis_port;
            } catch (\Throwable $e) {
                $last_error = $e->getMessage();
            }

            try {
                $redis->close();
            } catch (\Throwable) {
            }
        }

        return [null, $redis_host, $last_error];
    }

    private function is_loopback_host(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === '127.0.0.1' || $host === 'localhost';
    }
}

final class ArrayUserProvider implements UserProviderInterface
{
    /** @param array<string, AuthenticatableInterface> $users */
    public function __construct(private array $users) {}

    public function find_by_credentials(array $credentials): ?AuthenticatableInterface
    {
        return null;
    }

    public function find_by_id(string $auth_id): ?AuthenticatableInterface
    {
        return $this->users[$auth_id] ?? null;
    }
}

final class RoleUser implements AuthenticatableInterface, HasRolesInterface
{
    /** @param string[] $roles */
    public function __construct(
        private string $auth_id,
        private array $roles,
    ) {}

    public function get_auth_id(): string
    {
        return $this->auth_id;
    }

    public function get_password_hash(): ?string
    {
        return null;
    }

    public function get_role_slugs(): array
    {
        return $this->roles;
    }
}
