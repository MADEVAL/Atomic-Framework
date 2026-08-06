<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    // Supported drivers: folder, redis, memcached.
    'default'  => 'folder',
    'path'     => 'storage/framework/cache/',
    'prefix'   => 'atomic.',
];
