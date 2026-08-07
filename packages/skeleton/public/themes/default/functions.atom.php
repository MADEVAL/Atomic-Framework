<?php

declare(strict_types=1);
/*
 * Atomic Default Theme Functions
 */

if (!defined('ATOMIC_START')) exit;

define('THEME_DIR', get_theme_dir());
define('THEME_URL', get_theme_uri());
define('PUBLIC_URL', get_public_uri());
define('DEFAULT_THEME_VERSION', '1.0.0');

if (is_home()) {
    enqueue_style('atomic-landing', '~assets/css/atomic_landing.css', [], DEFAULT_THEME_VERSION);
}

if (is_page(['/login', '/register'])) {
    enqueue_style('atomic-core', '~assets/css/atomic_core.css', [], DEFAULT_THEME_VERSION);
    enqueue_script('atomic-core', '~assets/js/atomic_core.js', [], DEFAULT_THEME_VERSION, true);
    enqueue_script('atomic-auth', '~assets/js/atomic_auth.js', ['atomic-core'], DEFAULT_THEME_VERSION, true);
}

if (is_section('account')) {
    enqueue_style('atomic-core', '~assets/css/atomic_core.css', [], DEFAULT_THEME_VERSION);
    enqueue_style('atomic-dashboard', '~assets/css/atomic_dashboard.css', [], DEFAULT_THEME_VERSION);
    enqueue_script('atomic-core', '~assets/js/atomic_core.js', [], DEFAULT_THEME_VERSION, true);
}