<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'driver'       => 'smtp',
    'host'         => 'smtp.example.com',  // MAIL_HOST from .env
    'port'         => 587,
    'username'     => '',                   // MAIL_USERNAME from .env
    'password'     => '',                   // MAIL_PASSWORD from .env
    'encryption'   => 'tls',
    'from_address' => 'no-reply@example.com',
    'from_name'    => 'Atomic',
];
