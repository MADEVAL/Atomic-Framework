<?php
declare(strict_types=1);

define('ATOMIC_START', microtime(true));
define('ATOMIC_ROOT', dirname(__DIR__));

$app = require ATOMIC_ROOT . '/bootstrap/app.php';

$app->run();
