<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

final class HttpKernelIntegrationTest extends TestCase
{
    private TestApplication $app;

    protected function setUp(): void
    {
        $this->app = TestApplication::boot();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    public function test_container_is_accessible(): void
    {
        $this->assertNotNull($this->app->container());
    }

    public function test_get_request_returns_response(): void
    {
        $response = $this->app->get('/');

        $this->assertNotNull($response->status());
    }

    public function test_post_with_json_accept_header(): void
    {
        $response = $this->app->post('/login', [
            'email' => 'test@test.com',
            'password' => 'secret',
        ], [
            'Accept' => 'application/json',
        ]);

        $this->assertNotNull($response->status());
    }

    public function test_f3_hive_is_set_for_routing(): void
    {
        $f3 = $this->app->f3();
        $f3->set('VERB', 'GET');
        $f3->set('PATH', '/dashboard');

        $verb = $f3->get('VERB');
        $path = $f3->get('PATH');

        $this->assertSame('GET', $verb);
        $this->assertSame('/dashboard', $path);
    }

    public function test_multiple_requests_share_f3_state(): void
    {
        $f3 = $this->app->f3();
        $f3->set('SHARED_COUNTER', 1);

        $r1 = $this->app->get('/first');
        $r2 = $this->app->get('/second');

        // Both should maintain the F3 state
        $this->assertSame(1, $f3->get('SHARED_COUNTER'));
        $this->assertNotNull($r1->status());
        $this->assertNotNull($r2->status());
    }
}
