<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

$this->route('POST /webhook/monopay', 'Engine\Atomic\Plugins\Monopay\WebhookHandler->handle');
