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
    'name'         => 'Atomic',
    'key'          => '',           // APP_KEY from .env
    'uuid'         => '',           // APP_UUID from .env
    'encryption_key' => '',         // APP_ENCRYPTION_KEY from .env
    'domain'       => '',           // DOMAIN from .env
    'timezone'     => 'UTC',
    'theme'        => 'default',
    'encoding'     => 'UTF-8',
    'language'     => 'en',
    'fallback'     => 'en',
    'debug'        => false,
    'debug_level'  => 'error',
    'escape'       => true,
    'paths' => [
        'ui'                  => 'public/themes/',
        'temp'                => 'storage/framework/cache/data/',
        'logs'                => 'storage/logs/',
        'locales'             => 'engine/Atomic/Lang/locales/',
        'fonts'               => 'storage/framework/fonts/',
        'fonts_temp'          => 'storage/framework/cache/fonts/',
        'migrations'          => 'database/migrations/',
        'migrations_core'     => 'engine/Atomic/Core/Database/Migrations/',
        'seeds'               => 'database/seeds/',
        'user_plugins'        => 'plugins/',
        'framework_routes'    => 'engine/Atomic/Core/Routes/',
    ],
    'cors' => [
        'headers'     => 'Content-Type,Authorization',
        'origin'      => '*',
        'credentials' => false,
        'expose'      => 'Authorization',
        'ttl'         => 86400,
    ],
];
