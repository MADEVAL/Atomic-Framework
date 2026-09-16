<?php

declare(strict_types=1);

/*
* Atomic Telemetry Functions
* Atomic Framework
*/

if(!defined('ATOMIC_START')) exit;

defined('THEME_URL')   || define('THEME_URL', get_theme_uri());
defined('PUBLIC_URL')  || define('PUBLIC_URL', get_public_uri());

enqueue_style('atomic-error', '~assets/css/atomic-errors.css', [], '1.0.0');

if (is_page('/error/400')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #ff9800;
        --secondary-color: #f57c00;
    }');
}

if (is_page('/error/401')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #f44336;
        --secondary-color: #d32f2f;
    }');
}

if (is_page('/error/403')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #f44336;
        --secondary-color: #d32f2f;
    }');
}

if (is_page('/error/404')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #2196f3;
        --secondary-color: #1976d2;
    }');
}

if (is_page('/error/405')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #ff9800;
        --secondary-color: #f57c00;
    }');
}

if (is_page('/error/408')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #ffc107;
        --secondary-color: #ffa000;
    }');
}

if (is_page('/error/429')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #ff9800;
        --secondary-color: #f57c00;
    }');
}

if (is_page('/error/500')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #f44336;
        --secondary-color: #d32f2f;
    }');
}

if (is_page('/error/502')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #3f51b5;
        --secondary-color: #303f9f;
    }');
}

if (is_page('/error/503')) {
    add_inline_style('atomic-error', 
    ':root {
        --primary-color: #9e9e9e;
        --secondary-color: #757575;
    }');
}