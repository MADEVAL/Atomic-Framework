<?php

declare(strict_types=1);

if(!defined('ATOMIC_START')) exit;

return [
    'errorPage' => [
        'developer' => 'Developed by ',
        'home' => 'Home',
        'back' => 'Back'
    ],
    'error400' => [
        'title' => 'Bad Request',
        'subtitle' => 'Bad Request',
        'description' => 'The server cannot process the request due to malformed syntax. Please check the data you are sending and try again.',
    ],
    'error401' => [
        'title' => 'Unauthorized',
        'subtitle' => 'Unauthorized',
        'description' => 'Access to the requested resource requires authentication. Please provide valid credentials to proceed.',
    ],
    'error403' => [
        'title' => 'Forbidden',
        'subtitle' => 'Forbidden',
        'description' => 'You do not have permission to access the requested resource. If you believe this is an error, please contact the administrator.',
    ],
    'error404' => [
        'title' => 'Page Not Found',
        'subtitle' => 'Not Found',
        'description' => 'The requested page does not exist or has been moved. Please check the URL and try again.',
    ],
    'error405' => [
        'title' => 'Method Not Allowed',
        'subtitle' => 'Method Not Allowed',
        'description' => 'The requested method is not available for the specified resource. Please check your request and try again.',
    ],
    'error408' => [
        'title' => 'Request Timeout',
        'subtitle' => 'Request Timeout',
        'description' => 'The server closed the connection due to a timeout while waiting for the request. Please check your internet connection and try again.',
    ],
    'error429' => [
        'title' => 'Too Many Requests',
        'subtitle' => 'Too Many Requests',
        'description' => 'You have sent too many requests in a short period of time. Please wait a while and try again later.',
    ],
    'error500' => [
        'title' => 'Internal Server Error',
        'subtitle' => 'Internal Server Error',
        'description' => 'An unexpected error occurred on the server that prevented it from fulfilling the request. We are already working to fix it. Please try again later.',
    ],
    'error502' => [
        'title' => 'Bad Gateway',
        'subtitle' => 'Bad Gateway',
        'description' => 'The server, while acting as a gateway or proxy, received an invalid response from the upstream server. Please try again later.',
    ],
    'error503' => [
        'title' => 'Service Unavailable',
        'subtitle' => 'Service Unavailable',
        'description' => 'The service is temporarily unavailable due to overload or maintenance. We are working to resolve the issue. Please try again later.',
    ],
];