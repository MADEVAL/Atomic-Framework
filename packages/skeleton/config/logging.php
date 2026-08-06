<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'log'      => 'storage/logs/',
    'default'  => 'stack',
    'channels' => [
        'log'      => [],
        'messages' => [],
    ],
];
