<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [

    /*
    |--------------------------------------------------------------------------
    | Core Plugins
    |--------------------------------------------------------------------------
    |
    | Plugins registered during application bootstrap.
    | Each entry must be a fully-qualified class name implementing Plugin.
    |
    */
    'plugins' => [
        // Engine\Atomic\Plugins\YourPlugin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth User Provider
    |--------------------------------------------------------------------------
    |
    | Class that implements UserProviderInterface for authentication.
    |
    */
    'user_provider' => App\Auth\UserProvider::class,

];
