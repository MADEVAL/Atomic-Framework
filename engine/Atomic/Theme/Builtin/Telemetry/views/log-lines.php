<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\LogLine;
use Engine\Atomic\Telemetry\Data\LogPage;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, LogPage $page, Links $links): void {
    // Row ids are renumbered once the lines join the loaded ones.
    foreach ($page->lines as $line) {
        echo $ui->log_line($line, $links);
    }
    if ($page->has_more()) {
        echo $ui->log_more($links->part('log-lines', $page->next()));
    }
};
