<?php
declare(strict_types=1);

namespace Tests\Engine\WebSockets;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\MiddlewareStack;
use Engine\Atomic\WebSockets\Connection;
use Engine\Atomic\WebSockets\RoutedWebSocketServer;
use Engine\Atomic\WebSockets\WebSocketConnectMiddleware;
use Engine\Atomic\WebSockets\WebSocketDispatcher;
use Engine\Atomic\WebSockets\WebSocketMiddleware;
use Engine\Atomic\WebSockets\WebSocketRouter;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Request;

class WebSocketMiddlewarePassStub implements WebSocketMiddleware
{
    public static array $calls = [];

    public function __construct(private ?string $param = null) {}

    public function handle(Connection $conn, string $message, array $params): bool
    {
        self::$calls[] = [$this->param, $message, $params];

        return true;
    }
}

class WebSocketMiddlewareBlockStub implements WebSocketMiddleware
{
    public static array $calls = [];

    public function handle(Connection $conn, string $message, array $params): bool
    {
        self::$calls[] = [$message, $params];

        return false;
    }
}

class WebSocketHandlerStub
{
    public static array $calls = [];

    public static function receive(Connection $conn, string $message, array $params): void
    {
        self::$calls[] = [$message, $params, $conn->get('quota_key')];
    }
}

class WebSocketConnectMiddlewarePassStub implements WebSocketConnectMiddleware
{
    public static array $calls = [];

    public function __construct(private ?string $param = null) {}

    public function handle(Connection $conn, Request $request, array $params): bool
    {
        self::$calls[] = [$this->param, $params];
        $conn->set('quota_key', 'ai:' . $params['job_id']);

        return true;
    }
}

class WebSocketConnectMiddlewareBlockStub implements WebSocketConnectMiddleware
{
    public static array $calls = [];

    public function handle(Connection $conn, Request $request, array $params): bool
    {
        self::$calls[] = [$params];

        return false;
    }
}

class WebSocketDispatcherConnectionStub extends Connection
{
    public array $sent = [];
    public bool $closed = false;

    public function __construct(string $path)
    {
        $this->set_path($path);
    }

    public function send(string $data): bool
    {
        $this->sent[] = $data;

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

class WebSocketDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        $ref = new \ReflectionClass(MiddlewareStack::class);
        $ref->getProperty('aliases')->setValue(null, []);
        $ref->getProperty('routeMap')->setValue(null, []);

        App::instance()->set('WS_ROUTES', []);
        WebSocketMiddlewarePassStub::$calls = [];
        WebSocketMiddlewareBlockStub::$calls = [];
        WebSocketConnectMiddlewarePassStub::$calls = [];
        WebSocketConnectMiddlewareBlockStub::$calls = [];
        WebSocketHandlerStub::$calls = [];
    }

    public function test_dispatch_resolves_route_middleware_aliases(): void
    {
        MiddlewareStack::register_alias('ws-pass', WebSocketMiddlewarePassStub::class);
        WebSocketRouter::register(
            '/jobs/@job_id',
            WebSocketHandlerStub::class . '::receive',
            ['ws-pass:guard']
        );

        $conn = $this->connection_for_path('/jobs/123');

        (new WebSocketDispatcher())->dispatch($conn, '{"type":"ping"}');

        $this->assertSame([['guard', '{"type":"ping"}', ['job_id' => '123']]], WebSocketMiddlewarePassStub::$calls);
        $this->assertSame([[ '{"type":"ping"}', ['job_id' => '123'], null]], WebSocketHandlerStub::$calls);
    }

    public function test_dispatch_connect_resolves_connect_middleware_and_sets_connection_attributes(): void
    {
        MiddlewareStack::register_alias('ws-connect', WebSocketConnectMiddlewarePassStub::class);
        WebSocketRouter::register(
            '/jobs/@job_id',
            WebSocketHandlerStub::class . '::receive',
            [
                'connect' => ['ws-connect:auth'],
                'message' => ['ws-pass:guard'],
            ]
        );

        $conn = $this->connection_for_path('/jobs/123');

        $result = (new WebSocketDispatcher())->dispatch_connect($conn, $this->request_stub());

        $this->assertTrue($result);
        $this->assertSame([['auth', ['job_id' => '123']]], WebSocketConnectMiddlewarePassStub::$calls);
        $this->assertTrue($conn->has('quota_key'));
        $this->assertSame('ai:123', $conn->get('quota_key'));

        MiddlewareStack::register_alias('ws-pass', WebSocketMiddlewarePassStub::class);
        (new WebSocketDispatcher())->dispatch($conn, '{"type":"ping"}');

        $this->assertSame([[ '{"type":"ping"}', ['job_id' => '123'], 'ai:123']], WebSocketHandlerStub::$calls);
    }

    public function test_dispatch_rejects_invalid_middleware(): void
    {
        WebSocketRouter::register(
            '/jobs',
            WebSocketHandlerStub::class . '::receive',
            ['missing-auth']
        );

        try {
            (new WebSocketDispatcher())->dispatch($this->connection_for_path('/jobs'), '{}');
            $this->fail('Invalid WebSocket middleware did not throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid WebSocket middleware: missing-auth', $e->getMessage());
        }

        $this->assertSame([], WebSocketHandlerStub::$calls);
    }

    public function test_dispatch_sends_failure_and_closes_unknown_route(): void
    {
        $conn = $this->connection_for_path('/missing');

        (new WebSocketDispatcher())->dispatch($conn, '{}');

        $this->assertSame([
            json_encode([
                'status' => 'failed',
                'error' => 'Unknown WebSocket route',
            ]),
        ], $conn->sent);
        $this->assertTrue($conn->closed);
        $this->assertSame([], WebSocketHandlerStub::$calls);
    }

    public function test_dispatch_stops_when_message_middleware_blocks(): void
    {
        MiddlewareStack::register_alias('ws-block', WebSocketMiddlewareBlockStub::class);
        WebSocketRouter::register(
            '/jobs/@job_id',
            WebSocketHandlerStub::class . '::receive',
            ['ws-block']
        );

        (new WebSocketDispatcher())->dispatch($this->connection_for_path('/jobs/123'), '{"type":"ping"}');

        $this->assertSame([[ '{"type":"ping"}', ['job_id' => '123']]], WebSocketMiddlewareBlockStub::$calls);
        $this->assertSame([], WebSocketHandlerStub::$calls);
    }

    public function test_dispatch_connect_closes_when_connect_middleware_blocks(): void
    {
        MiddlewareStack::register_alias('ws-connect-block', WebSocketConnectMiddlewareBlockStub::class);
        WebSocketRouter::register(
            '/jobs/@job_id',
            WebSocketHandlerStub::class . '::receive',
            ['connect' => ['ws-connect-block']]
        );

        $conn = $this->connection_for_path('/jobs/123');

        $result = (new WebSocketDispatcher())->dispatch_connect($conn, $this->request_stub());

        $this->assertFalse($result);
        $this->assertTrue($conn->closed);
        $this->assertSame([[['job_id' => '123']]], WebSocketConnectMiddlewareBlockStub::$calls);
    }

    public function test_dispatch_connect_rejects_invalid_connect_middleware(): void
    {
        WebSocketRouter::register(
            '/jobs',
            WebSocketHandlerStub::class . '::receive',
            ['connect' => ['missing-connect']]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid WebSocket connect middleware: missing-connect');

        (new WebSocketDispatcher())->dispatch_connect($this->connection_for_path('/jobs'), $this->request_stub());
    }

    public function test_routed_server_dispatches_messages(): void
    {
        WebSocketRouter::register(
            '/jobs/@job_id',
            WebSocketHandlerStub::class . '::receive'
        );

        $server = new class('tcp://127.0.0.1:0') extends RoutedWebSocketServer {
            public function test_message(Connection $conn, string $data): void
            {
                $this->on_message($conn, $data, 1);
            }
        };

        $server->test_message($this->connection_for_path('/jobs/456'), '{"type":"ping"}');

        $this->assertSame([[ '{"type":"ping"}', ['job_id' => '456'], null]], WebSocketHandlerStub::$calls);
    }

    public function test_register_rejects_http_method_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid WebSocket route pattern: GET /jobs');

        WebSocketRouter::register(
            'GET /jobs',
            WebSocketHandlerStub::class . '::receive'
        );
    }

    private function connection_for_path(string $path): WebSocketDispatcherConnectionStub
    {
        return new WebSocketDispatcherConnectionStub($path);
    }

    private function request_stub(): Request
    {
        $ref = new \ReflectionClass(Request::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
