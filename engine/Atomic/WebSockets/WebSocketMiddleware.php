<?php
declare(strict_types=1);
namespace Engine\Atomic\WebSockets;

if (!defined('ATOMIC_START')) exit;

interface WebSocketMiddleware
{
    public function handle(Connection $conn, string $message, array $params): bool;
}
