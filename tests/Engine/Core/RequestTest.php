<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        $this->request = Request::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame(Request::instance(), Request::instance());
    }

    public function test_parsed_body_decodes_json(): void
    {
        $result = $this->request->parsed_body('{"name":"Atomic","count":2}', 'application/json; charset=utf-8');

        $this->assertSame(['name' => 'Atomic', 'count' => 2], $result);
    }

    public function test_parsed_body_decodes_form_data(): void
    {
        $result = $this->request->parsed_body('name=Atomic&tags%5B0%5D=php', 'application/x-www-form-urlencoded');

        $this->assertSame(['name' => 'Atomic', 'tags' => ['php']], $result);
    }

    public function test_parsed_body_returns_empty_array_for_invalid_json(): void
    {
        $this->assertSame([], $this->request->parsed_body('{bad-json', 'application/json'));
    }

    public function test_is_json_request_uses_server_content_type(): void
    {
        $previous = $_SERVER['CONTENT_TYPE'] ?? null;
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        try {
            $this->assertTrue($this->request->is_json_request());
        } finally {
            if ($previous === null) {
                unset($_SERVER['CONTENT_TYPE']);
            } else {
                $_SERVER['CONTENT_TYPE'] = $previous;
            }
        }
    }

    public function test_remote_get_returns_array(): void
    {
        $result = $this->request->remote_get('https://httpbin.org/get', ['timeout' => 5]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('url', $result);
    }

    public function test_remote_get_with_query(): void
    {
        $result = $this->request->remote_get('https://httpbin.org/get', [
            'query' => ['foo' => 'bar', 'baz' => '42'],
            'timeout' => 5,
        ]);
        $this->assertIsArray($result);
        if ($result['ok']) {
            $body = json_decode($result['body'], true);
            $this->assertSame('bar', $body['args']['foo'] ?? null);
        }
    }

    public function test_remote_post_returns_array(): void
    {
        $result = $this->request->remote_post('https://httpbin.org/post', ['key' => 'value'], ['timeout' => 5]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        if ($result['ok']) {
            $body = json_decode($result['body'], true);
            $this->assertSame('value', $body['form']['key'] ?? null);
        }
    }

    public function test_remote_get_invalid_host(): void
    {
        $result = $this->request->remote_get('https://invalid.host.example.test/', ['timeout' => 2, 'retries' => 0]);
        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
    }

    public function test_remote_head(): void
    {
        $result = $this->request->remote_head('https://httpbin.org/get', ['timeout' => 5]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
    }

    public function test_result_structure(): void
    {
        $result = $this->request->remote_get('https://httpbin.org/status/404', ['timeout' => 5]);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('raw_headers', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('cached', $result);
        if ($result['status'] === 404) {
            $this->assertFalse($result['ok']);
        }
    }
}
