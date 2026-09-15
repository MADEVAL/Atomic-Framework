<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\ConfigUser;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\Middleware\AccessMiddleware;
use Engine\Atomic\Core\Response as LegacyResponse;
use Engine\Atomic\Http\Request;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AccessMiddlewareFlowTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        App::instance()->set('CLI', false);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
    }

    public static function redirect_cases(): iterable
    {
        $current = '/telemetry/logs?level=error';
        $targets = [
            'requested page' => [null, $current, $current],
            'explicit local URL' => ['/telemetry/hive?filter=1', $current, '/telemetry/hive?filter=1'],
            'external URL' => ['https://evil.com', $current, $current],
            'protocol relative' => ['//evil.com', $current, $current],
            'backslash' => ['/\\evil.com', $current, $current],
            'CRLF' => ["/telemetry\r\nX-Test: injected", $current, $current],
            'NUL' => ["/telemetry\x00", $current, $current],
            'DEL' => ["/telemetry\x7F", $current, $current],
            'unsafe fallback' => ['https://evil.com', '//evil.com', '/'],
        ];
        foreach (['kernel' => true, 'legacy' => false] as $path => $kernel) {
            foreach ($targets as $name => [$target, $uri, $expected]) {
                yield "$path $name" => [$kernel, 'text/html', $target, $uri, $expected];
            }
            yield "$path JSON login" => [$kernel, 'application/json', null, $current, $current];
        }
    }

    #[DataProvider('redirect_cases')]
    public function test_successful_login_redirects_safely(
        bool $kernel, string $accept, ?string $target, string $uri, string $expected,
    ): void {
        // Credential verification is covered separately; isolate the middleware response contract.
        $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()
            ->onlyMethods(['login_with_secret'])->getMock();
        $auth->expects($this->once())->method('login_with_secret')
            ->with(['username' => 'viewer', 'guard' => 'telemetry'], 'secret')
            ->willReturn(new ConfigUser('11111111-1111-4111-8111-111111111111', 'viewer', 'unused', ['admin']));
        Container::global_or_create()->instance(Auth::class, $auth);
        Auth::reset();

        if (!$kernel) {
            // CLI PHP cannot expose Location via headers_list(); inspect the response boundary.
            $response = $this->getMockBuilder(LegacyResponse::class)->disableOriginalConstructor()
                ->onlyMethods(['redirect'])->getMock();
            $response->expects($this->once())->method('redirect')->with($expected, 303, false);
            Container::global_or_create()->instance(LegacyResponse::class, $response);
            LegacyResponse::reset();
        }

        $body = ['username' => 'viewer', 'key' => 'secret'];
        if ($target !== null) {
            $body['redirect'] = $target;
        }
        $response = $this->dispatch($kernel, 'POST', $uri, $accept, $body);
        if ($kernel) {
            $this->assertSame(303, $response->status());
            $this->assertSame($expected, $response->header('Location'));
        }
    }

    public static function denied_requests(): iterable
    {
        foreach (['kernel' => true, 'legacy' => false] as $path => $kernel) {
            foreach (['HTML' => 'text/html', 'JSON' => 'application/json'] as $format => $accept) {
                yield "$path $format guest" => [$kernel, $accept, 'GET', []];
                yield "$path $format invalid secret" => [$kernel, $accept, 'POST', ['username' => 'viewer', 'key' => 'wrong']];
                yield "$path $format missing credentials" => [$kernel, $accept, 'POST', []];
            }
        }
    }

    #[DataProvider('denied_requests')]
    public function test_unauthorized_response_does_not_start_a_session(
        bool $kernel, string $accept, string $method, array $body,
    ): void {
        $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()
            ->onlyMethods(['login_with_secret'])->getMock();
        $auth->expects($body === [] ? $this->never() : $this->once())->method('login_with_secret')
            ->with(['username' => 'viewer', 'guard' => 'telemetry'], 'wrong')->willReturn(null);
        Container::global_or_create()->instance(Auth::class, $auth);
        Auth::reset();

        $response = $this->dispatch($kernel, $method, '/telemetry/logs?level=error', $accept, $body);
        $this->assertSame(401, $response->status());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        if ($accept === 'application/json') {
            $this->assertSame(['error' => 'Unauthorized'], json_decode($response->body(), true));
        } else {
            $this->assertStringContainsString('<form method="post">', $response->body());
            $this->assertStringContainsString('name="redirect" value="/telemetry/logs?level=error"', $response->body());
            if ($method === 'POST') {
                $this->assertStringContainsString('Invalid username or key.', $response->body());
            } else {
                $this->assertStringNotContainsString('Invalid username or key.', $response->body());
            }
        }
    }

    private function dispatch(bool $kernel, string $method, string $uri, string $accept, array $body): Response
    {
        $middleware = new AccessMiddleware('telemetry');
        if ($kernel) {
            return $middleware->process(new Request($method, $uri, ['Accept' => $accept], $body),
                function (): Response {
                    $this->fail('An unauthenticated request must not invoke the next handler.');
                });
        }

        $atomic = \Base::instance();
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $atomic->set('VERB', $method);
        $atomic->set('PATH', $path);
        $atomic->set('QUERY', $query);
        $atomic->set('POST', $body);
        $atomic->set('HEADERS', ['Accept' => $accept]);
        http_response_code(200);
        ob_start();
        try {
            $this->assertFalse($middleware->handle($atomic));
            return Response::html((string)ob_get_contents(), http_response_code());
        } finally {
            ob_end_clean();
        }
    }
}
