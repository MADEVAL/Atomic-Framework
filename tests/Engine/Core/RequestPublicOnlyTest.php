<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\PublicUrlGuard;
use Engine\Atomic\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestPublicOnlyTest extends TestCase
{
    private const NOT_ALLOWED = 'URL not allowed: only public http/https URLs are permitted';
    private const RESPONSE_KEYS = ['ok', 'status', 'headers', 'raw_headers', 'body', 'engine', 'cached', 'error', 'url', 'request'];

    /** @var list<array{string, array<int, mixed>}> */
    private array $calls = [];

    private const DNS = [
        'shop.example.test'  => ['93.184.216.34'],
        'cdn.example.test'   => ['2606:4700::1111'],
        'intranet.example.test' => ['192.168.1.5'],
    ];

    /** @param list<callable(string, array): array> $responses */
    private function client(array $responses, ?callable $public_addresses = null): Request
    {
        $this->calls = [];
        $guard = new PublicUrlGuard(static fn(string $host): array => self::DNS[rtrim($host, '.')] ?? []);
        $transport = function (string $url, array $options) use (&$responses): array {
            $this->calls[] = [$url, $options];
            $next = array_shift($responses);
            self::assertNotNull($next, 'Unexpected request to ' . $url);
            return $next($url, $options);
        };

        $class = new \ReflectionClass(Request::class);
        $request = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($request, $public_addresses ?? $guard->resolve(...), $transport);
        return $request;
    }

    /** @param list<string> $headers */
    private static function reply(int $status, string $body = '', array $headers = []): callable
    {
        return static fn(): array => [
            'headers' => array_merge(["HTTP/1.1 {$status} X"], $headers),
            'body'    => $body,
            'error'   => '',
        ];
    }

    /** @param array<int, mixed> $options */
    private static function header(array $options, string $name): ?string
    {
        foreach ($options[CURLOPT_HTTPHEADER] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    public function test_private_literal_is_refused_without_a_request(): void
    {
        $client = $this->client([]);

        foreach (['http://127.0.0.1/', 'http://10.0.0.1/admin', 'http://[::1]/', 'file:///etc/passwd'] as $url) {
            $result = $client->remote_get($url, ['public_only' => true, 'retries' => 3]);

            self::assertFalse($result['ok']);
            self::assertSame(0, $result['status']);
            self::assertSame(self::NOT_ALLOWED, $result['error']);
            self::assertSame($url, $result['url']);
        }
        self::assertSame([], $this->calls);
    }

    public function test_hostname_resolving_to_private_address_is_refused(): void
    {
        $result = $this->client([])->remote_post('https://intranet.example.test/wp-json', ['a' => 1], ['public_only' => true]);

        self::assertSame(self::NOT_ALLOWED, $result['error']);
        self::assertSame([], $this->calls);
    }

    public function test_redirect_to_private_host_is_refused_at_that_hop(): void
    {
        $result = $this->client([
            self::reply(302, '', ['Location: http://intranet.example.test/secret']),
        ])->remote_get('https://shop.example.test/', ['public_only' => true]);

        self::assertFalse($result['ok']);
        self::assertSame(self::NOT_ALLOWED, $result['error']);
        self::assertCount(1, $this->calls);
    }

    public function test_redirect_to_public_host_is_followed_and_pinned_to_the_new_address(): void
    {
        $result = $this->client([
            self::reply(301, '', ['Location: https://cdn.example.test:8443/v3?x=1']),
            self::reply(200, '{"ok":true}', ['Content-Type: application/json']),
        ])->remote_get('https://shop.example.test/start', ['public_only' => true]);

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status']);
        self::assertSame('{"ok":true}', $result['body']);
        self::assertSame('application/json', $result['headers']['content-type']);
        self::assertSame('https://shop.example.test/start', $result['url']);

        [[$first_url, $first], [$second_url, $second]] = $this->calls;
        self::assertSame('https://shop.example.test/start', $first_url);
        self::assertSame(['::93.184.216.34:'], $first[CURLOPT_CONNECT_TO]);
        self::assertSame('https://cdn.example.test:8443/v3?x=1', $second_url);
        self::assertSame(['::[2606:4700::1111]:'], $second[CURLOPT_CONNECT_TO]);
        foreach ([$first, $second] as $options) {
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
            self::assertSame('', $options[CURLOPT_PROXY]);
            self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        }
    }

    public function test_too_many_hops_are_refused(): void
    {
        $hop = self::reply(302, '', ['Location: /again']);
        $result = $this->client([$hop, $hop, $hop])
            ->remote_get('https://shop.example.test/', ['public_only' => true, 'redirects' => 2, 'retries' => 2]);

        self::assertFalse($result['ok']);
        self::assertSame(302, $result['status']);
        self::assertSame('Too many redirects', $result['error']);
        self::assertCount(3, $this->calls);
    }

    public function test_follow_off_returns_the_redirect_response(): void
    {
        $result = $this->client([self::reply(302, '', ['Location: /next'])])
            ->remote_get('https://shop.example.test/', ['public_only' => true, 'follow' => false]);

        self::assertFalse($result['ok']);
        self::assertSame(302, $result['status']);
        self::assertSame('/next', $result['headers']['location']);
        self::assertCount(1, $this->calls);
    }

    public function test_303_becomes_get_without_body(): void
    {
        $this->client([
            self::reply(303, '', ['Location: /done']),
            self::reply(200),
        ])->remote_post('https://shop.example.test/orders', ['a' => 1], ['public_only' => true]);

        [[, $first], [$url, $second]] = $this->calls;
        self::assertSame('POST', $first[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('a=1', $first[CURLOPT_POSTFIELDS]);
        self::assertSame('https://shop.example.test/done', $url);
        self::assertSame('GET', $second[CURLOPT_CUSTOMREQUEST]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $second);
        self::assertNull(self::header($second, 'Content-Type'));
    }

    public function test_307_and_308_keep_method_and_body(): void
    {
        $this->client([
            self::reply(307, '', ['Location: /a']),
            self::reply(308, '', ['Location: /b']),
            self::reply(200),
        ])->remote_put('https://shop.example.test/orders/1', '{"x":1}', ['public_only' => true, 'headers' => ['Content-Type' => 'application/json']]);

        foreach (array_slice($this->calls, 1) as [, $options]) {
            self::assertSame('PUT', $options[CURLOPT_CUSTOMREQUEST]);
            self::assertSame('{"x":1}', $options[CURLOPT_POSTFIELDS]);
            self::assertSame('application/json', self::header($options, 'Content-Type'));
        }
    }

    public function test_authorization_is_kept_on_same_host_and_dropped_on_cross_host_redirect(): void
    {
        $this->client([
            self::reply(302, '', ['Location: /moved']),
            self::reply(302, '', ['Location: https://cdn.example.test/elsewhere']),
            self::reply(302, '', ['Location: https://shop.example.test/back']),
            self::reply(200),
        ])->remote_get('https://shop.example.test/', ['public_only' => true, 'headers' => ['Authorization' => 'Basic c2VjcmV0']]);

        $auth = array_map(static fn(array $call): ?string => self::header($call[1], 'Authorization'), $this->calls);
        self::assertSame(['Basic c2VjcmV0', 'Basic c2VjcmV0', null, null], $auth);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function credential_redirect_provider(): array
    {
        return [
            'same host upgrade'       => ['http://shop.example.test/a', 'https://shop.example.test/b', true],
            'same host, case and dot' => ['https://shop.example.test/a', 'https://SHOP.example.test./b', true],
            'https port change'       => ['https://shop.example.test/a', 'https://shop.example.test:8443/b', true],
            'downgrade'               => ['https://shop.example.test/a', 'http://shop.example.test/b', false],
            'http port change'        => ['http://shop.example.test/a', 'http://shop.example.test:8080/b', false],
            'host change'             => ['https://shop.example.test/a', 'https://cdn.example.test/b', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('credential_redirect_provider')]
    public function test_credentials_on_redirect(string $from, string $to, bool $kept): void
    {
        $this->client([self::reply(302, '', ['Location: ' . $to]), self::reply(200)])
            ->remote_get($from, ['public_only' => true, 'headers' => ['Authorization' => 'Basic c2VjcmV0', 'Cookie' => 'a=1']]);

        [, [$url, $options]] = $this->calls;
        self::assertSame($to, $url);
        self::assertSame($kept ? 'Basic c2VjcmV0' : null, self::header($options, 'Authorization'));
        self::assertSame($kept ? 'a=1' : null, self::header($options, 'Cookie'));
    }

    public function test_tls_verification_is_on_and_failures_are_not_downgraded(): void
    {
        $tls_error = static fn(): array => ['headers' => [], 'body' => '', 'error' => 'SSL certificate problem: self-signed certificate'];
        $result = $this->client([$tls_error, $tls_error])
            ->remote_get('https://shop.example.test/', ['public_only' => true, 'retries' => 1, 'retry_delay_ms' => 0]);

        self::assertFalse($result['ok']);
        self::assertSame('SSL certificate problem: self-signed certificate', $result['error']);
        self::assertCount(2, $this->calls);
        foreach ($this->calls as [, $options]) {
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        }
    }

    public function test_size_limit_defaults_to_20_mb_and_can_be_overridden(): void
    {
        $client = $this->client([self::reply(200), self::reply(200)]);
        $client->remote_get('https://shop.example.test/', ['public_only' => true]);
        $client->remote_get('https://shop.example.test/', ['public_only' => true, 'max_bytes' => 1024]);

        self::assertSame(20 * 1024 * 1024, $this->calls[0][1][CURLOPT_MAXFILESIZE]);
        self::assertSame(1024, $this->calls[1][1][CURLOPT_MAXFILESIZE]);
    }

    public function test_oversized_response_fails_without_retry(): void
    {
        $result = $this->client([
            static fn(): array => ['headers' => ['HTTP/1.1 200 OK'], 'body' => 'partial', 'error' => 'aborted', 'exceeded' => true],
        ])->remote_get('https://shop.example.test/', ['public_only' => true, 'retries' => 2]);

        self::assertFalse($result['ok']);
        self::assertSame('Response exceeds the size limit', $result['error']);
        self::assertSame('', $result['body']);
        self::assertCount(1, $this->calls);
    }

    public function test_spent_time_budget_stops_before_the_next_hop(): void
    {
        $result = $this->client([
            static function (): array {
                usleep(1_100_000);
                return ['headers' => ['HTTP/1.1 302 Found', 'Location: /next'], 'body' => '', 'error' => ''];
            },
        ])->remote_get('https://shop.example.test/', ['public_only' => true, 'timeout' => 1]);

        self::assertFalse($result['ok']);
        self::assertSame('Request timed out', $result['error']);
        self::assertCount(1, $this->calls);
    }

    public function test_response_shape_matches_the_default_path(): void
    {
        $result = $this->client([self::reply(200, 'hi', ['X-A: 1', 'X-A: 2'])])
            ->remote_get('https://shop.example.test/p', ['public_only' => true, 'query' => ['page' => 2]]);

        self::assertSame(self::RESPONSE_KEYS, array_keys($result));
        self::assertTrue($result['ok']);
        self::assertSame(['1', '2'], $result['headers']['x-a']);
        self::assertSame(['HTTP/1.1 200 X', 'X-A: 1', 'X-A: 2'], $result['raw_headers']);
        self::assertSame('cURL', $result['engine']);
        self::assertFalse($result['cached']);
        self::assertSame('', $result['error']);
        self::assertSame('https://shop.example.test/p?page=2', $result['url']);
        self::assertSame('GET https://shop.example.test/p?page=2', $result['request'][0]);

        $refused = $this->client([])->remote_get('http://127.0.0.1/', ['public_only' => true]);
        self::assertSame(self::RESPONSE_KEYS, array_keys($refused));
    }

    public function test_user_agent_encoding_timeout_and_headers_are_applied(): void
    {
        $this->client([self::reply(200)])->remote_get('https://shop.example.test/', [
            'public_only' => true,
            'user_agent'  => 'Shop/1.0',
            'encoding'    => 'gzip',
            'timeout'     => 7,
            'headers'     => ['X-Key' => 'k'],
        ]);

        [[, $options]] = $this->calls;
        self::assertSame('Shop/1.0', $options[CURLOPT_USERAGENT]);
        self::assertSame('gzip', $options[CURLOPT_ENCODING]);
        self::assertLessThanOrEqual(7000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertGreaterThan(6000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame('k', self::header($options, 'X-Key'));
        self::assertNull(self::header($options, 'User-Agent'));
        self::assertNull(self::header($options, 'Accept-Encoding'));
    }

    public function test_retries_temporary_failures_but_not_refusals(): void
    {
        $result = $this->client([self::reply(503), self::reply(200, 'ok')])
            ->remote_get('https://shop.example.test/', ['public_only' => true, 'retries' => 1, 'retry_delay_ms' => 0]);

        self::assertTrue($result['ok']);
        self::assertCount(2, $this->calls);
    }

    public function test_public_only_off_keeps_the_default_client(): void
    {
        $client = $this->client([]);

        // A closed loopback port: the default client tries it, the public-only path would refuse it.
        $result = $client->remote_get('http://127.0.0.1:1/', ['timeout' => 1]);

        self::assertSame([], $this->calls);
        self::assertNotSame(self::NOT_ALLOWED, $result['error']);
        self::assertSame(self::RESPONSE_KEYS, array_keys($result));
    }

    public function test_real_curl_pins_hostname_and_follows_redirects_manually(): void
    {
        if (!function_exists('proc_open') || !function_exists('curl_init')) {
            self::markTestSkipped('proc_open and curl are required.');
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped('Cannot bind 127.0.0.1.');
        }
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $docroot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic-http-srv-' . bin2hex(random_bytes(6));
        mkdir($docroot);
        $router = $docroot . DIRECTORY_SEPARATOR . 'router.php';
        file_put_contents($router, '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/start") { header("Location: http://api-b.atomic.test:' . $port . '/final", true, 303); exit; }
if ($path === "/final") {
    $json = json_encode([
        "method" => $_SERVER["REQUEST_METHOD"],
        "host" => $_SERVER["HTTP_HOST"] ?? "",
        "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? "",
        "body" => file_get_contents("php://input"),
    ]);
    header("Content-Type: application/json");
    if (str_contains($_SERVER["HTTP_ACCEPT_ENCODING"] ?? "", "gzip")) {
        header("Content-Encoding: gzip");
        $json = gzencode($json);
    }
    echo $json;
    exit;
}
if ($path === "/big") {
    while (ob_get_level()) { ob_end_flush(); }
    for ($i = 0; $i < 32; $i++) { echo str_repeat("a", 65536); flush(); }
    exit;
}
if ($path === "/bomb") {
    header("Content-Encoding: gzip");
    echo gzencode(str_repeat("\0", 4 * 1024 * 1024), 9);
    exit;
}
http_response_code(404);
');
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $docroot . DIRECTORY_SEPARATOR . 'out.log', 'w'], 2 => ['file', $docroot . DIRECTORY_SEPARATOR . 'err.log', 'w']],
            $pipes,
            $docroot,
            null,
            ['bypass_shell' => true]
        );

        try {
            if (!is_resource($process)) {
                self::markTestSkipped('Cannot start php -S.');
            }
            $ready = false;
            for ($i = 0; $i < 50 && !$ready; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $ready = true;
                } else {
                    usleep(100000);
                }
            }
            if (!$ready) {
                self::markTestSkipped('php -S did not start.');
            }

            // The test hostnames do not exist in DNS, so reaching the server proves pinning.
            $class = new \ReflectionClass(Request::class);
            $client = $class->newInstanceWithoutConstructor();
            $class->getConstructor()->invoke($client, static fn(string $url): array =>
                str_ends_with((string) parse_url($url, PHP_URL_HOST), '.atomic.test') ? ['127.0.0.1'] : []);

            $result = $client->remote_post('http://api-a.atomic.test:' . $port . '/start', ['a' => 1], [
                'public_only' => true,
                'timeout'     => 10,
                'headers'     => ['Authorization' => 'Basic c2VjcmV0'],
            ]);

            self::assertTrue($result['ok'], $result['error']);
            self::assertSame(200, $result['status']);
            self::assertSame('gzip', $result['headers']['content-encoding']);
            self::assertSame(
                ['method' => 'GET', 'host' => 'api-b.atomic.test:' . $port, 'auth' => '', 'body' => ''],
                json_decode($result['body'], true)
            );

            // 2 MB of raw, chunked body without Content-Length against a 256 KB limit.
            $big = $client->remote_get('http://api-a.atomic.test:' . $port . '/big', ['public_only' => true, 'max_bytes' => 256 * 1024]);
            self::assertFalse($big['ok']);
            self::assertSame('Response exceeds the size limit', $big['error']);

            // A few KB on the wire that decode to 4 MB, more than 4x a 256 KB limit.
            $bomb = $client->remote_get('http://api-a.atomic.test:' . $port . '/bomb', ['public_only' => true, 'max_bytes' => 256 * 1024]);
            self::assertFalse($bomb['ok']);
            self::assertSame('Response exceeds the size limit', $bomb['error']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($docroot . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($docroot);
        }
    }
}
