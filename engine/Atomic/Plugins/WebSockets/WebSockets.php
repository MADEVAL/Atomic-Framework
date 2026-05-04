<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;

class WebSockets extends Plugin
{
    protected string $version = '1.0.0';

    protected function get_name(): string
    {
        return 'WebSockets';
    }

    public function register(): void
    {
        $autoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        $this->atomic->register_route_type('websocket', 'websocket.php');
    }
}
