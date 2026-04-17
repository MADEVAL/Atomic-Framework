<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'default'  => 'atomic',                  // LOG_DEFAULT_CHANNEL
    'channels' => [
        'atomic' => [
            'driver' => 'file',              // LOG_ATOMIC_DRIVER
            'path'   => 'atomic.log',        // LOG_ATOMIC_PATH
            'level'  => 'debug',             // LOG_ATOMIC_LEVEL
        ],
        'error' => [
            'driver' => 'file',              // LOG_ERROR_DRIVER
            'path'   => 'error.log',         // LOG_ERROR_PATH
            'level'  => 'error',             // LOG_ERROR_LEVEL
        ],
        'auth' => [
            'driver' => 'file',              // LOG_AUTH_DRIVER
            'path'   => 'auth.log',          // LOG_AUTH_PATH
            'level'  => 'info',              // LOG_AUTH_LEVEL
        ],
        'queue_worker' => [
            'driver' => 'file',              // LOG_QUEUE_WORKER_DRIVER
            'path'   => 'queue_worker.log',  // LOG_QUEUE_WORKER_PATH
            'level'  => 'debug',             // LOG_QUEUE_WORKER_LEVEL
        ],
        'queue_monitor' => [
            'driver' => 'file',              // LOG_QUEUE_MONITOR_DRIVER
            'path'   => 'queue_monitor.log', // LOG_QUEUE_MONITOR_PATH
            'level'  => 'info',              // LOG_QUEUE_MONITOR_LEVEL
        ],
    ],
];
