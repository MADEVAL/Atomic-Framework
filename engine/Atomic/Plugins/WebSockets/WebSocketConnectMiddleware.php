<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\WebSockets;

if (!defined('ATOMIC_START')) exit;

use Workerman\Protocols\Http\Request;

interface WebSocketConnectMiddleware
{
    public function handle(Connection $conn, Request $request, array $params): bool;
}
