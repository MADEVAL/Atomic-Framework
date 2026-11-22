<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\PublicUrlGuard;
use Engine\Atomic\Core\Upload;
use PHPUnit\Framework\TestCase;

class UploadUrlValidationTest extends TestCase
{
    private Upload $upload;

    protected function setUp(): void
    {
        $this->upload = Upload::instance();
    }

    public function test_rejects_file_protocol(): void
    {
        $result = $this->upload->download_image_from_url(
            'file:///etc/passwd',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_no_host(): void
    {
        $result = $this->upload->download_image_from_url(
            'not-a-valid-url',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_localhost(): void
    {
        $result = $this->upload->download_image_from_url(
            'https://localhost/secret',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_loopback_ip(): void
    {
        $result = $this->upload->download_image_from_url(
            'https://127.0.0.1/admin',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_private_ip(): void
    {
        $result = $this->upload->download_image_from_url(
            'https://10.0.0.1/internal',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_link_local_ip(): void
    {
        $result = $this->upload->download_image_from_url(
            'http://169.254.169.254/latest/meta-data/',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $result = $this->upload->download_image_from_url(
            'https://[::1]/image.jpg',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR
        );

        self::assertFalse($result['ok']);
        self::assertStringContainsString('URL not allowed', $result['error']);
    }

    public function test_url_allowed_hook_rejects_cgnat(): void
    {
        $method = new \ReflectionMethod(Upload::class, 'is_url_allowed');

        self::assertFalse($method->invoke($this->upload, 'https://100.64.0.1/image.jpg'));
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
    private const WEBP = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    private string $directory;

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory . '*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    private function directory(): string
    {
        return $this->directory ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic-upload-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR;
    }

    /** Feeds a response through the curl callbacks in $options, like curl_exec would. */
    private static function respond(array $options, int $status, string $body = '', array $headers = []): array
    {
        foreach (array_merge(["HTTP/1.1 {$status} X\r\n"], array_map(static fn(string $h): string => $h . "\r\n", $headers), ["\r\n"]) as $line) {
            self::assertSame(strlen($line), $options[CURLOPT_HEADERFUNCTION](null, $line));
        }
        foreach (str_split($body, 8192) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            if ($options[CURLOPT_WRITEFUNCTION](null, $chunk) !== strlen($chunk)) {
                return [false, $status];
            }
        }
        return [true, $status];
    }

    /**
     * Upload's constructor is protected (singleton), so build the mock without it and run it with the injected guard.
     *
     * @param list<string> $methods
     */
    private function mock_upload(array $methods, ?PublicUrlGuard $guard = null): Upload
    {
        $upload = $this->getMockBuilder(Upload::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
        (new \ReflectionMethod(Upload::class, '__construct'))->invoke($upload, null, $guard);
        return $upload;
    }

    /**
     * @param array<string, list<string>> $dns host => addresses
     * @param list<callable(string, array): array> $responses one per expected request
     * @param list<array{string, list<string>}> $calls receives [url, CURLOPT_CONNECT_TO] per request
     */
    private function fake_upload(array $dns, array $responses, ?array &$calls = []): Upload
    {
        $upload = $this->mock_upload(
            ['execute_image_request', 'build_public_url'],
            new PublicUrlGuard(static fn(string $host): array => $dns[$host] ?? [])
        );
        $upload->expects(self::exactly(count($responses)))->method('execute_image_request')->willReturnCallback(
            static function (string $url, array $options) use (&$responses, &$calls): array {
                $calls[] = [$url, $options[CURLOPT_CONNECT_TO]];
                return array_shift($responses)($url, $options);
            }
        );
        $upload->method('build_public_url')->willReturnCallback(
            static fn(string $path): string => 'https://app.example.test/uploads/' . basename($path)
        );
        return $upload;
    }

    private function assert_directory_empty(): void
    {
        self::assertSame([], glob($this->directory() . '*') ?: []);
    }

    public function test_uses_pinned_address_and_rejects_redirect_without_location(): void
    {
        $url = 'https://images.example.test:8443/image.jpg';
        $upload = $this->fake_upload(['images.example.test' => ['2606:4700::1111']], [
            static function (string $request_url, array $options) use ($url): array {
                self::assertSame($url, $request_url);
                self::assertSame(['::[2606:4700::1111]:'], $options[CURLOPT_CONNECT_TO]);
                self::assertSame('', $options[CURLOPT_PROXY]);
                self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
                self::assertSame(5, $options[CURLOPT_CONNECTTIMEOUT]);
                self::assertGreaterThan(19000, $options[CURLOPT_TIMEOUT_MS]);
                self::assertLessThanOrEqual(20000, $options[CURLOPT_TIMEOUT_MS]);
                self::assertSame(10 * 1024 * 1024, $options[CURLOPT_MAXFILESIZE]);
                self::assertArrayNotHasKey(CURLOPT_RETURNTRANSFER, $options);

                return self::respond($options, 302, '<a href="http://192.168.1.1/image.jpg">Moved</a>');
            },
        ]);

        self::assertSame(
            ['ok' => false, 'error' => 'Failed to download file from URL'],
            $upload->download_image_from_url($url, $this->directory())
        );
    }

    public function test_saves_public_image_with_default_filename_when_url_has_no_path(): void
    {
        $image = base64_decode(self::GIF, true);
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 200, $image),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8', $this->directory(), 'sample.gif');

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertMatchesRegularExpression('/^sample-[a-f0-9]{8}\.gif$/', $result['filename']);
        self::assertSame($this->directory() . $result['filename'], $result['src_path']);
        self::assertSame('https://app.example.test/uploads/' . $result['filename'], $result['url']);
        self::assertSame($image, file_get_contents($result['src_path']));
        self::assertSame(strlen($image), $result['size']);
        self::assertSame('image/gif', $result['mime']);
    }

    public function test_rejects_php_body_at_php_url_and_writes_nothing(): void
    {
        $tmpBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'img_*') ?: [];
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 200, '<?php system($_GET["c"]); ?><html></html>'),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/shell.php', $this->directory(), 'shell.php');

        self::assertSame(['ok' => false, 'error' => 'Downloaded file is not a supported image (JPEG, PNG, GIF, WebP)'], $result);
        self::assertFalse(is_dir($this->directory()));
        self::assertSame($tmpBefore, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'img_*') ?: []);
    }

    public function test_png_body_at_php_url_is_saved_as_png(): void
    {
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 200, base64_decode(self::PNG, true)),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/x.php', $this->directory(), 'evil.php');

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertMatchesRegularExpression('/^x-[a-f0-9]{8}\.png$/', $result['filename']);
        self::assertSame('image/png', $result['mime']);
    }

    /** @return array<string, array{string, string}> */
    public static function image_provider(): array
    {
        return [
            'gif' => [self::GIF, 'gif'],
            'jpeg' => [self::JPEG, 'jpg'],
            'webp' => [self::WEBP, 'webp'],
            'png' => [self::PNG, 'png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('image_provider')]
    public function test_accepts_supported_image_types(string $base64, string $extension): void
    {
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 200, base64_decode($base64, true)),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/photo.bin', $this->directory());

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertMatchesRegularExpression('/^photo-[a-f0-9]{8}\.' . $extension . '$/', $result['filename']);
    }

    public function test_rejects_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 200, $svg, ['Content-Type: image/svg+xml']),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/logo.svg', $this->directory());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('not a supported image', $result['error']);
        $this->assert_directory_empty();
    }

    public function test_aborts_oversized_body(): void
    {
        $written = 0;
        $upload = $this->fake_upload([], [
            static function (string $url, array $options) use (&$written): array {
                // No Content-Length: the write callback must stop the transfer.
                $chunk = str_repeat('a', 1024 * 1024);
                for ($i = 0; $i < 20; $i++) {
                    if ($options[CURLOPT_WRITEFUNCTION](null, $chunk) !== strlen($chunk)) {
                        return [false, 200];
                    }
                    $written += strlen($chunk);
                }
                return [true, 200];
            },
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/huge.png', $this->directory());

        self::assertSame(['ok' => false, 'error' => 'Downloaded file exceeds the size limit'], $result);
        self::assertSame(10 * 1024 * 1024, $written);
        $this->assert_directory_empty();
    }

    public function test_aborts_when_announced_size_exceeds_limit(): void
    {
        $upload = $this->fake_upload([], [
            static function (string $url, array $options): array {
                self::assertFalse($options[CURLOPT_NOPROGRESS]);
                self::assertSame(1, $options[CURLOPT_PROGRESSFUNCTION](null, 10 * 1024 * 1024 + 1, 0, 0, 0));
                return [false, 200];
            },
        ]);

        self::assertSame(
            ['ok' => false, 'error' => 'Downloaded file exceeds the size limit'],
            $upload->download_image_from_url('https://8.8.8.8/huge.png', $this->directory())
        );
    }

    public function test_follows_redirect_to_public_host_and_pins_new_address(): void
    {
        $calls = [];
        $upload = $this->fake_upload(
            ['a.example.test' => ['93.184.216.34'], 'cdn.example.test' => ['151.101.1.1']],
            [
                static fn(string $url, array $options): array => self::respond($options, 301, '', ['Location: https://cdn.example.test/img/pic.php']),
                static fn(string $url, array $options): array => self::respond($options, 200, base64_decode(self::PNG, true)),
            ],
            $calls
        );

        $result = $upload->download_image_from_url('https://a.example.test/start', $this->directory());

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame([
            ['https://a.example.test/start', ['::93.184.216.34:']],
            ['https://cdn.example.test/img/pic.php', ['::151.101.1.1:']],
        ], $calls);
        self::assertMatchesRegularExpression('/^start-[a-f0-9]{8}\.png$/', $result['filename']);
    }

    public function test_rejects_redirect_to_private_host(): void
    {
        $upload = $this->fake_upload(
            ['a.example.test' => ['93.184.216.34'], 'internal.example.test' => ['10.0.0.5']],
            [static fn(string $url, array $options): array => self::respond($options, 302, '', ['Location: http://internal.example.test/x.png'])]
        );

        self::assertSame(
            ['ok' => false, 'error' => 'URL not allowed: only public http/https URLs are permitted'],
            $upload->download_image_from_url('https://a.example.test/start', $this->directory())
        );
        $this->assert_directory_empty();
    }

    public function test_rejects_redirect_to_private_ip_literal(): void
    {
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 307, '', ['Location: http://169.254.169.254/latest/meta-data/']),
        ]);

        self::assertFalse($upload->download_image_from_url('https://8.8.8.8/start', $this->directory())['ok']);
    }

    public function test_rejects_more_than_three_redirects(): void
    {
        $hop = static fn(string $url, array $options): array => self::respond($options, 302, '', ['Location: /next']);
        $upload = $this->fake_upload([], [$hop, $hop, $hop, $hop]);

        self::assertSame(
            ['ok' => false, 'error' => 'Too many redirects'],
            $upload->download_image_from_url('https://8.8.8.8/start', $this->directory())
        );
    }

    public function test_follows_three_redirects(): void
    {
        $hop = static fn(string $url, array $options): array => self::respond($options, 302, 'moved', ['Location: /next']);
        $upload = $this->fake_upload([], [
            $hop, $hop, $hop,
            static fn(string $url, array $options): array => self::respond($options, 200, base64_decode(self::GIF, true)),
        ]);

        $result = $upload->download_image_from_url('https://8.8.8.8/start', $this->directory());

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame(base64_decode(self::GIF, true), file_get_contents($result['src_path']));
    }

    /** @return array<string, array{string, string}> */
    public static function relative_location_provider(): array
    {
        return [
            'relative path' => ['images/pic.png?s=1', 'https://8.8.8.8:8443/a/b/images/pic.png?s=1'],
            'dot segments' => ['../c/./pic.png', 'https://8.8.8.8:8443/a/c/pic.png'],
            'absolute path' => ['/root.png#frag', 'https://8.8.8.8:8443/root.png'],
            'query only' => ['?v=2', 'https://8.8.8.8:8443/a/b/start?v=2'],
            'scheme relative' => ['//1.1.1.1/x.png', 'https://1.1.1.1/x.png'],
            'too many parents' => ['../../../../x.png', 'https://8.8.8.8:8443/x.png'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relative_location_provider')]
    public function test_resolves_relative_location(string $location, string $expected): void
    {
        $calls = [];
        $upload = $this->fake_upload([], [
            static fn(string $url, array $options): array => self::respond($options, 302, '', ['Location: ' . $location]),
            static fn(string $url, array $options): array => self::respond($options, 200, base64_decode(self::PNG, true)),
        ], $calls);

        $result = $upload->download_image_from_url('https://8.8.8.8:8443/a/b/start?q=1', $this->directory());

        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertSame($expected, $calls[1][0]);
    }

    public function test_real_curl_pins_hostname_and_aborts_oversized_body(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available.');
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped('Cannot bind 127.0.0.1.');
        }
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $docroot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic-upload-srv-' . bin2hex(random_bytes(6));
        mkdir($docroot);
        $router = $docroot . DIRECTORY_SEPARATOR . 'router.php';
        file_put_contents($router, '<?php
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($_SERVER["HTTP_HOST"] !== "img-a.atomic.test:' . $port . '" && $_SERVER["HTTP_HOST"] !== "img-b.atomic.test:' . $port . '") {
    http_response_code(421); exit;
}
if ($path === "/hop") { header("Location: http://img-b.atomic.test:' . $port . '/pic.php", true, 302); exit; }
if ($path === "/pic.php") { header("Content-Type: text/html"); echo base64_decode("' . self::PNG . '"); exit; }
if ($path === "/big") {
    while (ob_get_level()) { ob_end_flush(); }
    for ($i = 0; $i < 64; $i++) { echo str_repeat("a", 65536); flush(); }
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

            $upload = $this->mock_upload(['is_url_allowed', 'resolve_download_addresses', 'build_public_url']);
            $upload->method('is_url_allowed')->willReturn(true);
            // Only the test hostnames map to loopback; they do not exist in DNS, so success proves pinning.
            $upload->method('resolve_download_addresses')->willReturnCallback(
                static fn(string $url): array => str_ends_with((string) parse_url($url, PHP_URL_HOST), '.atomic.test') ? ['127.0.0.1'] : []
            );
            $upload->method('build_public_url')->willReturnCallback(
                static fn(string $path): string => 'https://app.example.test/uploads/' . basename($path)
            );
            $limit = new \ReflectionProperty(Upload::class, 'download_max_bytes');
            $limit->setValue($upload, 256 * 1024);

            $result = $upload->download_image_from_url('http://img-a.atomic.test:' . $port . '/hop', $this->directory());
            self::assertTrue($result['ok'], $result['error'] ?? '');
            self::assertMatchesRegularExpression('/^hop-[a-f0-9]{8}\.png$/', $result['filename']);
            self::assertSame(base64_decode(self::PNG, true), file_get_contents($result['src_path']));

            self::assertSame(
                ['ok' => false, 'error' => 'Downloaded file exceeds the size limit'],
                $upload->download_image_from_url('http://img-a.atomic.test:' . $port . '/big', $this->directory())
            );
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

    public function test_subclass_cannot_bypass_public_address_validation(): void
    {
        $upload = $this->mock_upload(
            ['is_url_allowed', 'execute_image_request'],
            new PublicUrlGuard(static fn(string $host): array => ['10.0.0.1'])
        );
        $upload->expects(self::once())->method('is_url_allowed')->willReturn(true);
        $upload->expects(self::never())->method('execute_image_request');

        self::assertSame(
            ['ok' => false, 'error' => 'URL not allowed: only public http/https URLs are permitted'],
            $upload->download_image_from_url('https://images.example.test/image.jpg', sys_get_temp_dir())
        );
    }
}
