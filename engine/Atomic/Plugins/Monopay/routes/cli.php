<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$this->route('GET /monopay/migrations/publish [cli]', 'Engine\Atomic\Plugins\Monopay\Monopay->publish_migrations');
$this->route('GET /monopay/reconcile [cli]', 'Engine\Atomic\Plugins\Monopay\Reconciler->run_cli');
