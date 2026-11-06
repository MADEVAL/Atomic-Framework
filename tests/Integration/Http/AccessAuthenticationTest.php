<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\ConfigUserStore;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Middleware\AccessMiddleware;
use Engine\Atomic\Core\Middleware\RoleMiddleware;
use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Http\Request;
use Engine\Atomic\Http\Response;
use Engine\Atomic\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Engine\Session\Support\SessionDriverTestHarness;
use Tests\Support\TempPath;
use Tests\Support\TestConfig;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccessAuthenticationTest extends TestCase
{
    use SessionDriverTestHarness;

    private ?string $root = null;
    private ?\Redis $redis = null;
    private string $redis_prefix = '';
    private string $db_prefix = '';
    private string $user_id;

    protected function setUp(): void
    {
        $this->backup_session_state();
        [, $host] = $this->connect_pdo_or_skip();
        $this->db_prefix = $this->new_db_prefix();
        $this->configure_db_for_session($host, $this->db_prefix);
        $this->migrate_session_table_up();
        $this->db()->exec('CREATE TABLE `' . $this->db_prefix . 'meta` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid VARCHAR(128) NOT NULL, `key` VARCHAR(128) NOT NULL, value TEXT NULL,
            created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
        )');
        TestConfig::ensure_options_table(\Base::instance());

        $this->root = TempPath::make_dir('atomic_access_flow_');
        $this->user_id = ID::uuid_v4();
        $store = new ConfigUserStore($this->root);
        $this->assertTrue($store->upsert_user('telemetry', 'viewer', $this->user_id, password_hash('secret', PASSWORD_DEFAULT), ['admin']));
        App::instance()->set('ACCESS', $store->read());
        App::instance()->set('APP_UUID', ID::uuid_v4());
        App::instance()->set('CACHE_CONFIG', TestConfig::cache([
            'default' => 'folder', 'path' => $this->root . 'cache/', 'prefix' => 'access-test.',
        ]));
        TestConfig::reset_managers();
        $this->configure_request_context();
        App::instance()->set('AGENT', 'Atomic Test Agent');
        App::instance()->set('CLI', false);
        App::instance()->set('LOGGABLE', []);
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        if ($this->redis !== null) {
            $this->cleanup_redis_prefix($this->redis, $this->redis_prefix);
            $this->redis->close();
        }
        $this->migrate_session_table_down();
        if ($this->db_prefix !== '') {
            $this->db()->exec('DROP TABLE IF EXISTS `' . $this->db_prefix . 'meta`, `' . $this->db_prefix . 'options`');
        }
        $this->restore_session_state();
        if ($this->root !== null) {
            TempPath::remove($this->root);
        }
    }

    public static function request_paths(): array
    {
        return [
            'DB legacy' => ['db', false, 'text/html'],
            'DB kernel' => ['db', true, 'text/html'],
            'Redis legacy' => ['redis', false, 'text/html'],
            'Redis kernel' => ['redis', true, 'text/html'],
            'DB legacy JSON' => ['db', false, 'application/json'],
            'DB kernel JSON' => ['db', true, 'application/json'],
        ];
    }

    public static function redirect_targets(): array
    {
        return [
            'local path' => ['/telemetry/hive', '/telemetry/hive'],
            'absolute URL' => ['https://example.com/', '/telemetry/logs?level=error'],
            'protocol relative' => ['//example.com/', '/telemetry/logs?level=error'],
            'backslash authority' => ['/\\example.com/', '/telemetry/logs?level=error'],
            'header separator' => ["/telemetry\r\nX-Test: injected", '/telemetry/logs?level=error'],
        ];
    }

    #[DataProvider('redirect_targets')]
    public function test_kernel_login_redirect_stays_on_a_local_path(string $target, string $expected): void
    {
        $this->boot_request();
        $response = $this->dispatch(true, 'POST', [
            'username' => 'viewer', 'password' => 'secret', 'redirect' => $target,
        ], 'text/html');

        $this->assertSame(303, $response->status());
        $this->assertSame($expected, $response->headers()['Location'] ?? null);
    }

    #[DataProvider('request_paths')]
    public function test_file_login_survives_redirect_and_refresh_with_configured_session_driver(
        string $driver, bool $kernel, string $accept,
    ): void {
        if ($driver === 'redis') {
            [$this->redis, $host] = $this->connect_redis_or_skip();
            $this->redis_prefix = $this->new_redis_prefix();
            App::instance()->set('SESSION_CONFIG.redis_prefix', $this->redis_prefix);
            $this->configure_redis_for_session($this->redis, $host, $this->redis_prefix);
        }

        $this->boot_request();
        $response = $this->dispatch($kernel, 'GET', [], $accept);
        $this->assertSame(401, $response->status());
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'Showing the login form must not start the native PHP handler.');

        $response = $this->dispatch($kernel, 'POST', ['username' => 'viewer', 'password' => 'wrong'], $accept);
        $this->assertSame(401, $response->status());
        $this->assertEmpty($_SESSION['user_uuid'] ?? null);

        $response = $this->dispatch($kernel, 'POST', ['username' => 'viewer', 'password' => 'secret'], $accept);
        $this->assertSame(303, $response->status());
        if ($kernel) {
            $this->assertSame('/telemetry/logs?level=error', $response->headers()['Location'] ?? null);
        }
        $this->assertSame($this->user_id, $_SESSION['user_uuid'] ?? null);
        $session_id = session_id();
        session_write_close();

        if ($driver === 'db') {
            $stored = $this->db_session_row($session_id);
        } else {
            $stored = json_decode((string)$this->redis->get($this->redis_session_key($session_id)), true);
        }
        $this->assertIsArray($stored, 'Login must persist to the configured session driver.');
        $this->assertSame('127.0.0.1', $stored['ip']);
        $this->assertSame('Atomic Test Agent', $stored['agent']);

        for ($request = 0; $request < 2; $request++) {
            $this->boot_request($session_id);
            $response = $this->dispatch($kernel, 'GET', [], $accept);
            $this->assertSame(200, $response->status());
            $this->assertSame('telemetry allowed', $response->body());
            $this->assertSame($this->user_id, Auth::instance()->get_current_user()?->get_auth_id());
            session_write_close();
        }
    }

    private function boot_request(?string $session_id = null): void
    {
        $_SESSION = [];
        session_id($session_id ?? '');
        App::instance()->set('ERROR', null);
        Hook::instance()->remove_action('SESSION_STARTED');
        Auth::reset();
        Auth::instance()->register_session_hooks();
        if ($session_id !== null) {
            Session::start();
            $this->assertNull(App::instance()->get('ERROR'), 'Session bootstrap must not produce a false 403.');
        }

        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('find_by_credentials');
        $provider->expects($this->never())->method('find_by_id');
        Auth::instance()->set_user_provider($provider);
    }

    private function dispatch(bool $kernel, string $method, array $body, string $accept): Response
    {
        $atomic = \Base::instance();
        $atomic->set('VERB', $method);
        $atomic->set('PATH', '/telemetry/logs');
        $atomic->set('QUERY', 'level=error');
        $atomic->set('POST', $body);
        $atomic->set('HEADERS.Accept', $accept);
        $middleware = new AccessMiddleware('telemetry');
        $role = new RoleMiddleware('admin');
        if ($kernel) {
            return $middleware->process(
                new Request($method, '/telemetry/logs?level=error', ['Accept' => $accept], $body),
                fn($request) => $role->process($request, fn() => Response::text('telemetry allowed')),
            );
        }

        http_response_code(200);
        ob_start();
        try {
            if ($middleware->handle($atomic) && $role->handle($atomic)) {
                echo 'telemetry allowed';
            }
            return Response::html((string)ob_get_contents(), http_response_code());
        } finally {
            ob_end_clean();
        }
    }
}
