<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'default'  => 'folder',
    'path'     => 'storage/framework/cache/',
    'prefix'   => 'atomic.',
    'server'   => 'localhost',
    'port'     => '',
    'password' => '',
    'login'    => '',
    // Opt in to F3's cross-request hive TTL persistence (Base::set($k,$v,$ttl)).
    'f3_hive_ttl' => false,
];
