<?php
declare(strict_types=1);

define('ATOMIC_START', microtime(true));
define('ATOMIC_ROOT', dirname(__DIR__));

require ATOMIC_ROOT . '/bootstrap/app.php';

\Engine\Atomic\Core\App::instance()->run();
