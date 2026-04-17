<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'default' => 'local',
    'disks'   => [
        'local' => [
            'driver' => 'local',
            'root'   => 'storage/app/private',
            'serve'  => true,
            'throw'  => false,
            'report' => false,
        ],
        'public' => [
            'driver'     => 'local',
            'root'       => 'storage/app/public',
            'url'        => '/storage',
            'visibility' => 'public',
            'throw'      => false,
            'report'     => false,
        ],
    ],
    'links' => [
        'public/storage' => 'storage/app/public',
    ],
];
