<?php

declare(strict_types=1);

namespace Tests\Engine\Http;

use Engine\Atomic\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_method_returns_http_method(): void
    {
        $request = new Request('GET', '/test', ['Host' => 'localhost']);

        $this->assertSame('GET', $request->method());
    }

    public function test_path_returns_uri_path(): void
    {
        $request = new Request('POST', '/api/users', []);

        $this->assertSame('/api/users', $request->path());
    }

    public function test_header_returns_value(): void
    {
        $request = new Request('GET', '/', ['X-Custom' => 'value', 'Accept' => 'application/json']);

        $this->assertSame('value', $request->header('X-Custom'));
        $this->assertSame('application/json', $request->header('Accept'));
    }

    public function test_header_returns_null_for_missing(): void
    {
        $request = new Request('GET', '/', []);

        $this->assertNull($request->header('X-Missing'));
    }

    public function test_input_returns_body_data(): void
    {
        $request = new Request('POST', '/submit', [], ['name' => 'John', 'email' => 'john@test.com']);

        $this->assertSame('John', $request->input('name'));
        $this->assertSame('john@test.com', $request->input('email'));
        $this->assertNull($request->input('missing'));
    }

    public function test_input_with_default(): void
    {
        $request = new Request('GET', '/', []);

        $this->assertSame('fallback', $request->input('missing', 'fallback'));
    }

    public function test_string_returns_string_value(): void
    {
        $request = new Request('POST', '/', [], ['id' => 42, 'name' => 'test']);

        $this->assertSame('test', $request->string('name'));
        $this->assertSame('42', $request->string('id'));
    }

    public function test_expects_json_detects_accept_header(): void
    {
        $jsonRequest = new Request('GET', '/api', ['Accept' => 'application/json']);
        $htmlRequest = new Request('GET', '/page', ['Accept' => 'text/html']);

        $this->assertTrue($jsonRequest->expectsJson());
        $this->assertFalse($htmlRequest->expectsJson());
    }

    public function test_ip_returns_remote_addr(): void
    {
        $request = new Request('GET', '/', [], [], '192.168.1.1');

        $this->assertSame('192.168.1.1', $request->ip());
    }

    public function test_full_url(): void
    {
        $request = new Request('GET', '/dashboard', []);

        $this->assertSame('/dashboard', $request->fullUrl());
    }

    public function test_is_ajax_detects_xml_http_request(): void
    {
        $ajaxRequest = new Request('GET', '/api', ['X-Requested-With' => 'XMLHttpRequest']);
        $normalRequest = new Request('GET', '/page', []);

        $this->assertTrue($ajaxRequest->isAjax());
        $this->assertFalse($normalRequest->isAjax());
    }
}
