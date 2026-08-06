<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$atomic->route('GET /ws/test [cli]', 'Engine\Atomic\Plugins\WebSockets\TestClient->run');
