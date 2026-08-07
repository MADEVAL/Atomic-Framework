<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'log'          => 'storage/logs/',
    'default'      => 'atomic',
    'channels'     => [
        'atomic'       => [
            'driver'   => 'file',
            'path'     => 'atomic.log',
            'level'    => 'debug',
            'max_days' => 30,
        ],
        'error'        => [
            'driver'   => 'file',
            'path'     => 'error.log',
            'level'    => 'error',
            'max_days' => 90,
        ],
        'auth'         => [
            'driver'   => 'file',
            'path'     => 'auth.log',
            'level'    => 'info',
            'max_days' => 60,
        ],
        'queue_worker' => [
            'driver'   => 'file',
            'path'     => 'queue_worker.log',
            'level'    => 'debug',
            'max_days' => 14,
        ],
        'queue_monitor' => [
            'driver'   => 'file',
            'path'     => 'queue_monitor.log',
            'level'    => 'info',
            'max_days' => 14,
        ],
    ],
];
