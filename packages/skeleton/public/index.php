<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

define('ATOMIC_START', microtime(true));

require __DIR__ . '/../bootstrap/const.php';
require ATOMIC_VENDOR . 'autoload.php';

$loader = new \Engine\Atomic\Core\Config\ConfigLoader(ATOMIC_DIR . '/.env');
$loader->load();

$atomic = \Engine\Atomic\Core\App::instance();

require ATOMIC_DIR . '/bootstrap/app.php';

$atomic->run();
