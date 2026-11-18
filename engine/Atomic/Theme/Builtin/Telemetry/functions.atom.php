<?php

declare(strict_types=1);

/*
* Atomic Telemetry Functions
* Atomic Framework
*/

if(!defined('ATOMIC_START')) exit;

$telemetry_version = '3.0.0';

// Styles in cascade order.
enqueue_style('geist', 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap');
enqueue_style('telemetry-tokens',     '~assets/css/tokens.css',     ['geist'], $telemetry_version);
enqueue_style('telemetry-base',       '~assets/css/base.css',       ['telemetry-tokens'], $telemetry_version);
enqueue_style('telemetry-layout',     '~assets/css/layout.css',     ['telemetry-base'], $telemetry_version);
enqueue_style('telemetry-components', '~assets/css/components.css', ['telemetry-layout'], $telemetry_version);
enqueue_style('telemetry-helpers',    '~assets/css/helpers.css',    ['telemetry-components'], $telemetry_version);
enqueue_style('telemetry-pages',      '~assets/css/pages.css',      ['telemetry-helpers'], $telemetry_version);
enqueue_style('telemetry-server',     '~assets/css/server.css',     ['telemetry-pages'], $telemetry_version);

enqueue_script('telemetry', '~assets/js/server.js', [], $telemetry_version, true, ['defer' => true]);
