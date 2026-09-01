<?php

declare(strict_types=1);

if (!defined('ATOMIC_START')) {
    exit;
}

if (!defined('ATOMIC_ROOT')) {
    define('ATOMIC_ROOT', dirname(__DIR__));
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'const.php';

$autoloader = require ATOMIC_VENDOR . 'autoload.php';

// The root Composer autoloader does not include the skeleton App namespace in monorepo mode.
$skeleton_app_dir = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'app';
if (is_dir($skeleton_app_dir)) {
    $autoloader->setPsr4('App\\', [$skeleton_app_dir . DIRECTORY_SEPARATOR]);
}
unset($skeleton_app_dir, $autoloader);

return \Engine\Atomic\Core\Bootstrap::boot();
