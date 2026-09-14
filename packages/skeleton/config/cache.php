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
    // Preserve F3's cross-request hive TTL fallback; set false to disable it.
    'f3_hive_ttl' => true,
];
