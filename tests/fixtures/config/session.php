<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'driver'          => 'file',         // db | redis
    'lifetime'        => 259200,         // 3 days
    'cookie'          => 'Atomic_Session',
    'kill_on_suspect' => true,
    'cookie_expire'   => 259200,
    'cookie_path'     => '/',
    'cookie_domain'   => '',
    'cookie_secure'   => false,
    'cookie_httponly'  => true,
    'cookie_samesite' => 'Lax',
];
