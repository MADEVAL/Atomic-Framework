<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$atomic->route('GET /monopay/migrations/publish [cli]', 'Engine\Atomic\Plugins\Monopay->publish_migrations');
