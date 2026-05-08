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

    public function required_dependencies(): array
    {
        return [
            [
                'package' => 'workerman/workerman',
                'classes' => [
                    \Workerman\Worker::class,
                    \Workerman\Connection\TcpConnection::class,
                ],
            ],
            [
                'package' => 'workerman/redis',
                'classes' => [
                    \Workerman\Redis\Client::class,
                ],
            ],
        ];
    }

    public function register(): void
    {
        $this->atomic->register_route_type('websocket', 'websocket.php');
    }
}
