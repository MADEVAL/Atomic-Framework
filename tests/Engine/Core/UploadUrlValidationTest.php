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

    public function test_uses_pinned_address_and_rejects_redirect_to_private_host(): void
    {
        $url = 'https://images.example.test:8443/image.jpg';
        $upload = $this->getMockBuilder(Upload::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['public_url_guard', 'execute_image_request'])
            ->getMock();
        $upload->method('public_url_guard')->willReturn(
            new PublicUrlGuard(static fn(string $host): array => ['2606:4700::1111'])
        );
        $upload->expects(self::once())->method('execute_image_request')->willReturnCallback(
            static function (string $request_url, array $options) use ($url): array {
                self::assertSame($url, $request_url);
                self::assertSame(['::[2606:4700::1111]:'], $options[CURLOPT_CONNECT_TO]);
                self::assertSame('', $options[CURLOPT_PROXY]);
                self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);

                // Simulate a redirect whose Location targets a private address.
                return ['<a href="http://192.168.1.1/image.jpg">Moved</a>', 302];
            }
        );

        self::assertSame(
            ['ok' => false, 'error' => 'Failed to download file from URL'],
            $upload->download_image_from_url($url, sys_get_temp_dir() . DIRECTORY_SEPARATOR)
        );
    }

    public function test_saves_public_image_with_default_filename_when_url_has_no_path(): void
    {
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic-upload-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR;
        $upload = $this->getMockBuilder(Upload::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute_image_request', 'build_public_url'])
            ->getMock();
        $upload->expects(self::once())->method('execute_image_request')->willReturn([$image, 200]);
        $upload->method('build_public_url')->willReturnCallback(
            static fn(string $path): string => 'https://app.example.test/uploads/' . basename($path)
        );

        try {
            $result = $upload->download_image_from_url('https://8.8.8.8', $directory, 'sample.gif');

            self::assertTrue($result['ok'], $result['error'] ?? '');
            self::assertMatchesRegularExpression('/^sample-[a-f0-9]{8}\.gif$/', $result['filename']);
            self::assertSame($directory . $result['filename'], $result['src_path']);
            self::assertSame('https://app.example.test/uploads/' . $result['filename'], $result['url']);
            self::assertSame($image, file_get_contents($result['src_path']));
            self::assertSame(strlen($image), $result['size']);
            self::assertSame('image/gif', $result['mime']);
        } finally {
            foreach (glob($directory . '*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_subclass_cannot_bypass_public_address_validation(): void
    {
        $upload = $this->getMockBuilder(Upload::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['is_url_allowed', 'public_url_guard', 'execute_image_request'])
            ->getMock();
        $upload->expects(self::once())->method('is_url_allowed')->willReturn(true);
        $upload->method('public_url_guard')->willReturn(
            new PublicUrlGuard(static fn(string $host): array => ['10.0.0.1'])
        );
        $upload->expects(self::never())->method('execute_image_request');

        self::assertSame(
            ['ok' => false, 'error' => 'URL not allowed: only public http/https URLs are permitted'],
            $upload->download_image_from_url('https://images.example.test/image.jpg', sys_get_temp_dir())
        );
    }
}
