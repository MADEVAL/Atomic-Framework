<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\JobEvent;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, JobEvent ...$events): void {
    echo $events
        ? $ui->timeline(...array_map(static fn (JobEvent $event): Html => $ui->step(
            $event->tone(),
            $event->description,
            $event->created . ' · batch ' . substr($event->batch, 0, 8),
            mono: true,
        ), $events))
        : Html::el('div', ['class' => 'muted'], 'No telemetry events for this job.');
};
