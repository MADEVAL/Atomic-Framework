<?php
declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$atomic->route('GET /error/400', 'Engine\Atomic\App\Error->error400');
$atomic->route('GET /error/401', 'Engine\Atomic\App\Error->error401');
$atomic->route('GET /error/403', 'Engine\Atomic\App\Error->error403');
$atomic->route('GET /error/404', 'Engine\Atomic\App\Error->error404');
$atomic->route('GET /error/405', 'Engine\Atomic\App\Error->error405');
$atomic->route('GET /error/408', 'Engine\Atomic\App\Error->error408');
$atomic->route('GET /error/429', 'Engine\Atomic\App\Error->error429');
$atomic->route('GET /error/500', 'Engine\Atomic\App\Error->error500');
$atomic->route('GET /error/502', 'Engine\Atomic\App\Error->error502');
$atomic->route('GET /error/503', 'Engine\Atomic\App\Error->error503');