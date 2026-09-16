<?php
declare(strict_types=1);

namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\CLI;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;

class RoutesTest extends TestCase
{
    private App $app;
    private mixed $original_routes;
    private mixed $original_websocket_routes;
    private mixed $original_framework_routes;

    protected function setUp(): void
    {
        $this->app = App::instance();
        $this->original_routes = $this->app->atomic()->get('ROUTES');
        $this->original_websocket_routes = $this->app->atomic()->get('WS_ROUTES');
        $this->original_framework_routes = $this->app->atomic()->get('FRAMEWORK_ROUTES');
    }

    protected function tearDown(): void
    {
        $this->app->atomic()->set('ROUTES', $this->original_routes);
        $this->app->atomic()->set('WS_ROUTES', $this->original_websocket_routes);
        $this->app->atomic()->set('FRAMEWORK_ROUTES', $this->original_framework_routes);
    }

    public function test_routes_can_filter_cli_custom_routes(): void
    {
        [$cli, $stream] = $this->cli_with_routes();

        try {
            $cli->list_routes(['cli', 'custom']);
            $output = Output::plain(StreamCapture::read($stream, true));

            $this->assertStringContainsString('/reports/rebuild', $output);
            $this->assertStringContainsString('CLI App Routes', $output);
            $this->assertStringNotContainsString('/help', $output);
            $this->assertStringNotContainsString('/chat', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_routes_all_scope_includes_websocket_routes(): void
    {
        [$cli, $stream] = $this->cli_with_routes();

        try {
            $cli->list_routes();
            $output = Output::plain(StreamCapture::read($stream, true));

            $this->assertStringContainsString('WebSocket Plugin Routes', $output);
            $this->assertStringContainsString('/chat', $output);
            $this->assertStringContainsString('/reports/rebuild', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_routes_are_grouped_like_help_without_repeating_group_metadata(): void
    {
        [$cli, $stream] = $this->cli_with_routes();

        try {
            $cli->list_routes(['cli', 'custom']);
            $output = Output::plain(StreamCapture::read($stream, true));

            $this->assertStringContainsString('Atomic Routes', $output);
            $this->assertSame(1, substr_count($output, 'CLI App Routes'));
            $this->assertStringContainsString('GET   /reports/rebuild - App\\Console\\Reports->rebuild', $output);
            $this->assertStringContainsString('POST  /reports/export  - App\\Console\\Reports->export', $output);
            $this->assertStringNotContainsString('Scope:', $output);
            $this->assertStringNotContainsString('Source:', $output);
        } finally {
            fclose($stream);
        }
    }

    public function test_invalid_filter_lists_available_route_commands(): void
    {
        [$cli, $stream] = $this->cli_with_routes();

        try {
            $cli->list_routes(['w']);
            $output = Output::plain(StreamCapture::read($stream, true));

            $this->assertStringContainsString("Unknown route scope or source 'w'.", $output);
            $this->assertStringContainsString('Available Route Commands', $output);
            $this->assertStringContainsString('php atomic routes/web', $output);
            $this->assertStringContainsString('php atomic routes/cli', $output);
            $this->assertStringContainsString('php atomic routes/websocket', $output);
            $this->assertStringContainsString('List WebSocket routes', $output);
            $this->assertStringContainsString('php atomic routes/all/framework', $output);
            $this->assertStringContainsString('List framework routes from every type', $output);
            $this->assertStringContainsString('php atomic routes/all/custom', $output);
            $this->assertStringNotContainsString('routes[/scope[/source]]', $output);
        } finally {
            fclose($stream);
        }
    }

    /** @return array{0: CLI, 1: resource} */
    private function cli_with_routes(): array
    {
        $this->app->atomic()->set(
            'FRAMEWORK_ROUTES',
            ATOMIC_ENGINE . 'Atomic' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Routes' . DIRECTORY_SEPARATOR,
        );
        $this->app->atomic()->set('ROUTES', [
            '/help' => [\Base::REQ_CLI => ['GET' => ['Engine\\Atomic\\App\\System->help']]],
            '/reports/rebuild' => [\Base::REQ_CLI => ['GET' => ['App\\Console\\Reports->rebuild']]],
            '/reports/export' => [\Base::REQ_CLI => ['POST' => ['App\\Console\\Reports->export']]],
        ]);
        $this->app->atomic()->set('WS_ROUTES', [
            '/chat' => ['handler' => 'Chat\\Server->message'],
        ]);

        $stream = StreamCapture::memory();
        $cli = new CLI();
        ReflectionHelper::set($cli, 'output', new Output($stream, $stream));

        return [$cli, $stream];
    }
}
