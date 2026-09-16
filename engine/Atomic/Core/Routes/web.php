<?php
declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$this->route(
    'GET /' . \Engine\Atomic\Theme\Theme::INTERNAL_URL_PREFIX . '/themes/@theme/*',
    'Engine\\Atomic\\Theme\\AssetController->serve'
);
