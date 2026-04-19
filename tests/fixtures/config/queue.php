<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'driver' => 'redis',           // db | redis
    'name'   => 'default',
    'db' => [
        'queues' => [
            'default' => [
                'delay'        => 0,
                'priority'     => 10,
                'timeout'      => 20,
                'max_attempts' => 3,
                'retry_delay'  => 5,
                'worker_cnt'   => 5,
                'ttl'          => 604800,
            ],
        ],
    ],
    'redis' => [
        'queues' => [
            'default' => [
                'delay'        => 0,
                'priority'     => 10,
                'timeout'      => 20,
                'max_attempts' => 3,
                'retry_delay'  => 5,
                'worker_cnt'   => 5,
                'ttl'          => 604800,
            ],
        ],
    ],
];
