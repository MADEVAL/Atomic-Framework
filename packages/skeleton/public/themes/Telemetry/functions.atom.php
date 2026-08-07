<?php

declare(strict_types=1);

/*
* Atomic Telemetry Functions
* Atomic Framework
*/

if(!defined('ATOMIC_START')) exit;

enqueue_style('w3css', 'https://www.w3schools.com/w3css/4/w3.css');
enqueue_style('lato', 'https://fonts.googleapis.com/css?family=Lato');
enqueue_style('montserrat', 'https://fonts.googleapis.com/css?family=Montserrat');
enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
enqueue_style('telemetry', '~assets/css/telemetry.css', ['w3css'], '1.0.0');
enqueue_script('telemetry', '~assets/js/telemetry.js', [], '1.0.0', true, ['defer' => true]);