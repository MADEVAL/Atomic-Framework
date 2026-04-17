<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'driver' => 'redis',           // database | redis
    'name'   => 'default',
    'database' => [
        'queues' => [
            'default' => [
                'delay'        => 0,
                'priority'     => 10,
                'timeout'      => 20,
                'max_attempts' => 3,
                'retry_delay'  => 5,
                'worker_cnt'   => 5,
                'batch_size'   => 1,
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
                'batch_size'   => 1,
                'ttl'          => 604800,
            ],
        ],
    ],
];
