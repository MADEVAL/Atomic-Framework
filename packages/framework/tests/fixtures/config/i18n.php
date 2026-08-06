<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'i18n' => [
        'languages' => ['en', 'ru'],
        'default'   => 'en',
        'url_mode'  => 'prefix',   // 'prefix' | 'param' | 'none'
        'ttl'       => 0,
        'cookie'    => 'lang',
        'session'   => 'lang',
    ],
];
