<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

return [
    'telegram' => [
        'bot_token' => '',
        'chat_id'   => '',
    ],

    'ai' => [
        'openai' => [
            'api_key' => ''
        ],
        'groq' => [
            'api_key' => ''
        ],
        'openrouter' => [
            'api_key' => ''
        ],
        'globus' => [
            'api_key' => ''
        ],
    ],
];
