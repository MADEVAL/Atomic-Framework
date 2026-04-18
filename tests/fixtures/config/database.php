<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'default'     => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => '127.0.0.1',
            'port'        => 3306,
            'database'    => 'atomic',
            'username'    => 'atomic',
            'password'    => 'pass',
            'unix_socket' => '',
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_general_ci',
        ],
    ],
    'redis' => [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',
        'db'       => 0,
    ],
    'memcached' => [
        'host'     => '127.0.0.1',
        'port'     => 11211,
        'username' => '',
        'password' => '',
        'prefix'   => 'atomic_',
    ],
    'mutex' => [
        'driver' => 'redis',
    ],
];
