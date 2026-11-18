<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\Dump;
use Engine\Atomic\Telemetry\Data\Json;
use Engine\Atomic\Telemetry\View\Highlight;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, Dump $dump): void {
    echo $ui->modal(
        $ui->head(title: $dump->id, kicker: 'Hive dump', mono: true),
        $ui->code('Dump JSON', Json::pretty($dump->data), highlighted: Highlight::json($dump->data)),
    );
};
