<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

class ResponseTest extends TestCase
{
    private Response $response;

    protected function setUp(): void
    {
        $this->response = Response::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame(Response::instance(), Response::instance());
    }

    public function test_atomic_json_encode_basic(): void
    {
        $result = $this->response->atomic_json_encode(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $result);
    }

    public function test_atomic_json_encode_unicode(): void
    {
        $result = $this->response->atomic_json_encode(['name' => 'Привіт']);
        $this->assertStringContainsString('Привіт', $result);
        $this->assertStringNotContainsString('\\u', $result);
    }

    public function test_atomic_json_encode_slashes(): void
    {
        $result = $this->response->atomic_json_encode(['url' => 'https://example.com/path']);
        $this->assertStringContainsString('https://example.com/path', $result);
    }

    public function test_atomic_json_encode_preserves_zero_fraction(): void
    {
        $result = $this->response->atomic_json_encode(['amount' => 10.0]);
        $this->assertStringContainsString('10.0', $result);
    }

    public function test_atomic_json_encode_pretty_print(): void
    {
        $result = $this->response->atomic_json_encode(['a' => 1, 'b' => 2], JSON_PRETTY_PRINT);
        $this->assertStringContainsString("\n", $result);
    }

    public function test_atomic_json_encode_nested(): void
    {
        $data = ['users' => [['id' => 1, 'name' => 'Test'], ['id' => 2, 'name' => 'User']]];
        $result = $this->response->atomic_json_encode($data);
        $decoded = json_decode($result, true);
        $this->assertCount(2, $decoded['users']);
    }

    public function test_atomic_json_encode_empty(): void
    {
        $this->assertSame('[]', $this->response->atomic_json_encode([]));
        $this->assertSame('{}', $this->response->atomic_json_encode(new \stdClass()));
    }

    public function test_sanitize_redirect_rejects_foreign_domain(): void
    {
        App::instance()->atomic()->set('DOMAIN', 'example.com');
        $result = ReflectionHelper::invoke($this->response, 'sanitize_redirect_url', ['https://notexample.com/phishing']);
        $this->assertSame(
            '/',
            $result,
            'Foreign domain notexample.com must redirect to / when DOMAIN=example.com'
        );
    }

    public function test_sanitize_redirect_allows_exact_domain(): void
    {
        App::instance()->atomic()->set('DOMAIN', 'example.com');
        $result = ReflectionHelper::invoke($this->response, 'sanitize_redirect_url', ['https://example.com/dashboard']);
        $this->assertSame('https://example.com/dashboard', $result);
    }

    public function test_sanitize_redirect_allows_subdomain(): void
    {
        App::instance()->atomic()->set('DOMAIN', 'example.com');
        $result = ReflectionHelper::invoke($this->response, 'sanitize_redirect_url', ['https://app.example.com/dashboard']);
        $this->assertSame('https://app.example.com/dashboard', $result);
    }
}
