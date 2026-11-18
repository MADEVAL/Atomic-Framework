<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\MigrationSource;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, MigrationSource $source): void {
    echo $ui->modal(
        $ui->head(title: $source->name, kicker: 'Migration file', mono: true),
        $ui->kv('Path', $source->file, mono: true, copy: $source->file, copy_title: 'Copy file path'),
        $ui->code('Source', $source->code, sub: $source->lines() . ' lines' . ($source->truncated ? ' · first 256 KB' : '')),
    );
};
