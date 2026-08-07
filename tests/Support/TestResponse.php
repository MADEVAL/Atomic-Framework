<?php

declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\Core\Container;
use Engine\Atomic\Core\F3Bridge;
use Engine\Atomic\Http\Response;

final class TestResponse
{
    public function __construct(private readonly Response $response) {}

    public function status(): int { return $this->response->status(); }

    public function body(): string { return $this->response->body(); }

    public function header(string $name): ?string { return $this->response->header($name); }

    /** @return mixed */
    public function json(): mixed
    {
        return json_decode($this->response->body(), true);
    }

    public function assertStatus(int $expected): self
    {
        \PHPUnit\Framework\Assert::assertSame($expected, $this->response->status());
        return $this;
    }

    public function assertRedirect(string $uri): self
    {
        \PHPUnit\Framework\Assert::assertTrue($this->response->isRedirect());
        \PHPUnit\Framework\Assert::assertStringContainsString($uri, $this->response->header('Location') ?? '');
        return $this;
    }

    public function assertSee(string $text): self
    {
        \PHPUnit\Framework\Assert::assertStringContainsString($text, $this->response->body());
        return $this;
    }

    public function assertDontSee(string $text): self
    {
        \PHPUnit\Framework\Assert::assertStringNotContainsString($text, $this->response->body());
        return $this;
    }

    /** @param array<string, mixed> $expected */
    public function assertJson(array $expected): self
    {
        $data = $this->json();
        \PHPUnit\Framework\Assert::assertNotNull($data);
        foreach ($expected as $key => $value) {
            \PHPUnit\Framework\Assert::assertArrayHasKey($key, $data);
            \PHPUnit\Framework\Assert::assertSame($value, $data[$key]);
        }
        return $this;
    }

    public function assertHeader(string $name, string $expected): self
    {
        \PHPUnit\Framework\Assert::assertSame($expected, $this->response->header($name));
        return $this;
    }

    public function assertCookie(string $name, ?string $value = null): self
    {
        $found = false;
        foreach ($this->response->cookies() as $cookie) {
            if ($cookie['name'] === $name) {
                $found = true;
                if ($value !== null) {
                    \PHPUnit\Framework\Assert::assertSame($value, $cookie['value']);
                }
                break;
            }
        }
        \PHPUnit\Framework\Assert::assertTrue($found, "Cookie '{$name}' not found");
        return $this;
    }

    public function unwrap(): Response
    {
        return $this->response;
    }
}
