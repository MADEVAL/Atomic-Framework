<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

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
}
