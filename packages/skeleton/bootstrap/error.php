<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) { exit; }

define('ATOMIC_PHP_ERRORS_CUSTOM', false);

$baseLogFile = ATOMIC_ENGINE . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'php_errors.log';
$logDir = dirname($baseLogFile);

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$dailyLogFile = $logDir . DIRECTORY_SEPARATOR . 'php_errors-' . date('Y-m-d') . '.log';

ini_set('log_errors', '1');
ini_set('html_errors', '0');

if (ATOMIC_PHP_ERRORS_CUSTOM) {
    ini_set('ignore_repeated_errors', '1'); // Ignore repeated errors
    ini_set('log_errors_max_len', '16384'); // Max length
}

ini_set('error_log', $dailyLogFile);

if (!file_exists($dailyLogFile)) {
    @file_put_contents($dailyLogFile, '');
    @chmod($dailyLogFile, 0664);
}
@chmod($logDir, 0775);

$logs = glob($logDir . DIRECTORY_SEPARATOR . 'php_errors-*.log');
if (is_array($logs) && count($logs) > 10) {
    natsort($logs);
    $excess = array_slice(array_values($logs), 0, count($logs) - 10);
    foreach ($excess as $old) { @unlink($old); }
}
