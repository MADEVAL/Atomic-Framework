<?php

declare(strict_types=1);

namespace Tests\Engine\Http;

use Engine\Atomic\Http\JsonResponse;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_html_factory(): void
    {
        $r = Response::html('<h1>Hello</h1>', 200);

        $this->assertSame(200, $r->status());
        $this->assertSame('<h1>Hello</h1>', $r->body());
    }

    public function test_json_factory_returns_json_response(): void
    {
        $r = Response::json(['ok' => true]);

        $this->assertInstanceOf(JsonResponse::class, $r);
        $this->assertSame(200, $r->status());
    }

    public function test_redirect_factory(): void
    {
        $r = Response::redirect('/dashboard');

        $this->assertSame(302, $r->status());
        $this->assertSame('/dashboard', $r->header('Location'));
        $this->assertTrue($r->isRedirect());
    }

    public function test_redirect_with_custom_status(): void
    {
        $r = Response::redirect('/new', 301);

        $this->assertSame(301, $r->status());
    }

    public function test_text_factory(): void
    {
        $r = Response::text('plain text', 200);

        $this->assertSame('plain text', $r->body());
        $this->assertSame('text/plain; charset=utf-8', $r->header('Content-Type'));
    }

    public function test_empty_factory(): void
    {
        $r = Response::empty(204);

        $this->assertSame(204, $r->status());
        $this->assertSame('', $r->body());
    }

    public function test_with_header_is_immutable(): void
    {
        $r1 = Response::html('body');
        $r2 = $r1->withHeader('X-Custom', 'value');

        $this->assertNull($r1->header('X-Custom'));
        $this->assertSame('value', $r2->header('X-Custom'));
        $this->assertNotSame($r1, $r2);
    }

    public function test_with_status_is_immutable(): void
    {
        $r1 = Response::html('body', 200);
        $r2 = $r1->withStatus(404);

        $this->assertSame(200, $r1->status());
        $this->assertSame(404, $r2->status());
    }

    public function test_with_body_is_immutable(): void
    {
        $r1 = Response::html('old');
        $r2 = $r1->withBody('new');

        $this->assertSame('old', $r1->body());
        $this->assertSame('new', $r2->body());
    }

    public function test_redirect_does_not_call_exit(): void
    {
        $r = Response::redirect('/login');
        // If exit was called, we would not reach this assertion
        $this->assertSame(302, $r->status());
    }

    public function test_json_response_contains_data(): void
    {
        $r = new JsonResponse(['error' => 'Unauthorized'], 401);

        $this->assertSame(401, $r->status());
        $this->assertSame('application/json; charset=utf-8', $r->header('Content-Type'));
        $this->assertSame('{"error":"Unauthorized"}', $r->body());
    }

    public function test_with_cookie(): void
    {
        $r = Response::html('body')->withCookie('session', 'abc123', [
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
        ]);

        $cookies = $r->cookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('session', $cookies[0]['name']);
        $this->assertSame('abc123', $cookies[0]['value']);
    }

    public function test_multiple_headers(): void
    {
        $r = Response::html('body')
            ->withHeader('X-One', '1')
            ->withHeader('X-Two', '2');

        $this->assertSame('1', $r->header('X-One'));
        $this->assertSame('2', $r->header('X-Two'));
    }
}
