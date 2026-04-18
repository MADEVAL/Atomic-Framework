<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

/*
|--------------------------------------------------------------------------
| Application Configuration (PHP loader mode)
|--------------------------------------------------------------------------
|
| Used only when ATOMIC_LOADER=php in bootstrap/const.php.
| With ATOMIC_LOADER=env (default), values are read from .env file.
|
*/

return [
    'name'           => 'Atomic',
    'key'            => '71d2b9b65208e039407c2a98700f691c',
    'uuid'           => 'de9b848a-d69f-42ae-b252-7dda458c0301',
    'encryption_key' => 'IPq3VwwlyFd/N2u8BWZRgot/10sm6pJTd5eqlhdR9yc=',
    'domain'         => 'http://localhost:8000/',
    'timezone'       => 'Europe/Kyiv',
    'theme'          => 'default',
    'encoding'       => 'UTF-8',
    'language'       => 'en',
    'debug'          => false,
    'debug_level'    => 'error',
    'paths' => [
        'ui'                  => 'public/themes/',
        'temp'                => 'storage/framework/cache/data/',
        'logs'                => 'storage/logs/',
        'locales'             => 'engine/Atomic/Lang/locales/',
        'fonts'               => 'storage/framework/fonts/',
        'fonts_temp'          => 'storage/framework/cache/fonts/',
        'migrations'          => 'database/migrations/',
        'seeds'               => 'database/seeds/',
        'migrations_core'     => 'engine/Atomic/Core/Database/Migrations/',
        'user_plugins'        => 'public/plugins/',
        'framework_routes'    => 'engine/Atomic/Core/Routes/',
    ],
    'websocket' => [
        'host'        => '0.0.0.0',
        'client_host' => '127.0.0.1',
        'port'        => 8080,
    ],
    'cors' => [
        'headers'     => 'Content-Type,Authorization',
        'origin'      => '*',
        'credentials' => true,
        'expose'      => 'Authorization',
        'ttl'         => 86400,
    ],
];
