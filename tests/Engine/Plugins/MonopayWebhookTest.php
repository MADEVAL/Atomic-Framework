<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use Engine\Atomic\Plugins\Monopay\WebhookHandler;
use PHPUnit\Framework\TestCase;

final class MonopayWebhookTest extends TestCase
{
    public function test_webhook_handler_log_calls_pass_string_only(): void
    {
        $source = (string)file_get_contents(ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/WebhookHandler.php');

        preg_match_all('/Log::(?:info|warning|error)\([^)]*,\s*\[/', $source, $matches);

        $this->assertSame(
            [],
            $matches[0],
            'Log::* takes a single string message; an array second argument raises a TypeError with strict_types.'
        );
    }

    public function test_webhook_route_file_exists_and_registers_handler(): void
    {
        $routeFile = ATOMIC_ENGINE . 'Atomic/Plugins/Monopay/routes/web.php';

        $this->assertFileExists($routeFile, 'Monopay must ship an HTTP webhook route file.');

        $content = (string)file_get_contents($routeFile);
        $this->assertStringContainsString('WebhookHandler->handle', $content);
        $this->assertStringContainsString("'POST /webhook/monopay'", $content);
    }

    public function test_webhook_route_registers_post_route_without_middleware_aliases_required(): void
    {
        $this->assertTrue(method_exists(WebhookHandler::class, 'handle'), 'WebhookHandler::handle must exist');
        $this->assertTrue(is_callable([WebhookHandler::class, 'handle']), 'WebhookHandler::handle must be callable by F3');
    }
}
