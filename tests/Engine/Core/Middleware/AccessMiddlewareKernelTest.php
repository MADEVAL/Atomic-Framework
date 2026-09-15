<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\ConfigUser;
use Engine\Atomic\Auth\ConfigUserStore;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use Engine\Atomic\App\Models\Meta;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Middleware\AccessMiddleware;
use Engine\Atomic\Http\Request;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;
use Tests\Support\TestConfig;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccessMiddlewareKernelTest extends TestCase
{
    private const USER_ID = '11111111-1111-4111-8111-111111111111';
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempPath::make_dir('atomic_access_kernel_');
        $store = new ConfigUserStore($this->root);
        $store->upsert_user('telemetry', 'viewer', self::USER_ID, password_hash('secret', PASSWORD_DEFAULT), ['admin']);
        App::instance()->set('ACCESS', $store->read());
        session_start();
    }

    protected function tearDown(): void
    {
        session_abort();
        TempPath::remove($this->root);
    }

    public function test_get_resolves_file_user_when_application_provider_is_registered(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('find_by_id');
        Auth::instance()->set_user_provider($provider);
        App::instance()->set('SESSION.user_uuid', self::USER_ID);

        $response = (new AccessMiddleware('telemetry'))->process(
            new Request('GET', '/telemetry'),
            fn() => Response::text('allowed'),
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(self::USER_ID, Auth::instance()->get_current_user()?->get_auth_id());
    }

    public function test_application_user_cached_before_access_guard_is_not_accepted(): void
    {
        $user = new ConfigUser('22222222-2222-4222-8222-222222222222', 'app-user', 'unused', ['admin']);
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn($user);
        Auth::instance()->set_user_provider($provider);
        App::instance()->set('SESSION.user_uuid', $user->get_auth_id());
        $this->assertSame($user, Auth::instance()->get_current_user());

        $response = (new AccessMiddleware('telemetry'))->process(
            new Request('GET', '/telemetry'),
            fn() => Response::text('allowed'),
        );

        $this->assertSame(401, $response->status());
        $this->assertNull(Auth::instance()->get_current_user());
    }

    public function test_handle_resolves_file_user_when_application_provider_is_registered(): void
    {
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('find_by_id');
        Auth::instance()->set_user_provider($provider);
        App::instance()->set('SESSION.user_uuid', self::USER_ID);

        $this->assertTrue((new AccessMiddleware('telemetry'))->handle(\Base::instance()));
        $this->assertSame(self::USER_ID, Auth::instance()->get_current_user()?->get_auth_id());
    }

    public function test_handle_rejects_cached_application_user(): void
    {
        $user = new ConfigUser('22222222-2222-4222-8222-222222222222', 'app-user', 'unused', ['admin']);
        $provider = $this->createStub(UserProviderInterface::class);
        $provider->method('find_by_id')->willReturn($user);
        Auth::instance()->set_user_provider($provider);
        App::instance()->set('SESSION.user_uuid', $user->get_auth_id());
        $this->assertSame($user, Auth::instance()->get_current_user());

        $atomic = \Base::instance();
        $atomic->set('VERB', 'GET');
        $atomic->set('CLI', false);
        $atomic->set('HEADERS.Accept', 'text/html');
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        ob_start();
        try {
            $allowed = (new AccessMiddleware('telemetry'))->handle($atomic);
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($allowed);
        $this->assertSame(401, http_response_code());
        $this->assertNull(Auth::instance()->get_current_user());
    }

    public static function middleware_paths(): array
    {
        return ['kernel' => [true], 'legacy' => [false]];
    }

    #[DataProvider('middleware_paths')]
    public function test_post_authenticates_file_user_when_application_provider_is_registered(bool $kernel): void
    {
        $atomic = \Base::instance();
        if (!$atomic->get('DB') instanceof \DB\SQL) {
            $this->markTestSkipped('MySQL is required for authentication session metadata.');
        }

        // Keep login rate limits isolated while exercising the real authentication service.
        $atomic->set('APP_UUID', ID::uuid_v4());
        $atomic->set('CACHE_CONFIG', TestConfig::cache([
            'default' => 'folder', 'path' => $this->root . '/cache/', 'prefix' => 'access-provider.',
        ]));
        TestConfig::reset_managers();
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->expects($this->never())->method('find_by_credentials');
        $provider->expects($this->never())->method('find_by_id');
        Auth::instance()->set_user_provider($provider);
        $this->assertEmpty($_SESSION['user_uuid'] ?? null);

        $body = ['username' => 'viewer', 'key' => 'secret'];
        $atomic->set('CLI', false);
        $atomic->set('VERB', 'POST');
        $atomic->set('POST', $body);
        $atomic->set('PATH', '/telemetry');
        $atomic->set('HEADERS.Accept', 'text/html');
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $middleware = new AccessMiddleware('telemetry');

        try {
            if ($kernel) {
                $response = $middleware->process(
                    new Request('POST', '/telemetry', ['Accept' => 'text/html'], $body),
                    function (): Response {
                        $this->fail('A login POST must redirect before invoking the next handler.');
                    },
                );
                $this->assertSame(303, $response->status());
            } else {
                ob_start();
                try {
                    $allowed = $middleware->handle($atomic);
                } finally {
                    ob_end_clean();
                }
                $this->assertFalse($allowed);
                $this->assertSame(303, http_response_code());
            }

            $this->assertSame(self::USER_ID, $_SESSION['user_uuid'] ?? null);
            $this->assertSame(self::USER_ID, Auth::instance()->get_current_user()?->get_auth_id());
        } finally {
            if (($_SESSION['user_uuid'] ?? null) === self::USER_ID) {
                Meta::delete_meta(self::USER_ID, 'auth_session_' . session_id());
            }
        }
    }

    public function test_login_form_preserves_requested_path_and_query(): void
    {
        $response = (new AccessMiddleware('telemetry'))->process(
            new Request('GET', '/telemetry/logs?level=error'),
            fn() => Response::text('allowed'),
        );

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('name="redirect" value="/telemetry/logs?level=error"', $response->body());
    }

    public function test_guest_form_does_not_start_an_unconfigured_session(): void
    {
        session_abort();
        $response = (new AccessMiddleware('telemetry'))->process(
            new Request('GET', '/telemetry'),
            fn() => Response::text('allowed'),
        );

        $this->assertSame(401, $response->status());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
}
