<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'default'  => 'atomic',                  // LOG_DEFAULT_CHANNEL
    'channels' => [
        'atomic' => [
            'driver'    => 'file',              // LOG_ATOMIC_DRIVER
            'path'      => 'atomic.log',        // LOG_ATOMIC_PATH
            'level'     => 'debug',             // LOG_ATOMIC_LEVEL
            'max_days'  => 30,                  // LOG_ATOMIC_MAX_DAYS
        ],
        'error' => [
            'driver'    => 'file',              // LOG_ERROR_DRIVER
            'path'      => 'error.log',         // LOG_ERROR_PATH
            'level'     => 'error',             // LOG_ERROR_LEVEL
            'max_days'  => 90,                  // LOG_ERROR_MAX_DAYS
        ],
        'auth' => [
            'driver'    => 'file',              // LOG_AUTH_DRIVER
            'path'      => 'auth.log',          // LOG_AUTH_PATH
            'level'     => 'info',              // LOG_AUTH_LEVEL
            'max_days'  => 60,                  // LOG_AUTH_MAX_DAYS
        ],
        'queue_worker' => [
            'driver'    => 'file',              // LOG_QUEUE_WORKER_DRIVER
            'path'      => 'queue_worker.log',  // LOG_QUEUE_WORKER_PATH
            'level'     => 'debug',             // LOG_QUEUE_WORKER_LEVEL
            'max_days'  => 14,                  // LOG_QUEUE_WORKER_MAX_DAYS
        ],
        'queue_monitor' => [
            'driver'    => 'file',              // LOG_QUEUE_MONITOR_DRIVER
            'path'      => 'queue_monitor.log', // LOG_QUEUE_MONITOR_PATH
            'level'     => 'info',              // LOG_QUEUE_MONITOR_LEVEL
            'max_days'  => 14,                  // LOG_QUEUE_MONITOR_MAX_DAYS
        ],
    ],
];
