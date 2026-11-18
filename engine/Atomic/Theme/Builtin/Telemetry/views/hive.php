<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\HiveNode;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, HiveNode ...$nodes): void {
    echo Html::el('div', ['class' => 'toolbar'],
        Html::el('span', ['class' => 'muted small'], 'Read-only snapshot of the request hive. Secrets are masked.'),
        Html::el('div', ['class' => 'toolbar-end'],
            Html::el('input', ['class' => 'input', 'placeholder' => 'Filter keys and values', 'data-tree-search' => true]),
            $ui->button('Collapse all', attrs: ['data-tree-toggle' => true])));
    echo Html::el('div', ['class' => 'hive-tree', 'data-tree' => true],
        array_map($ui->tree_node(...), $nodes),
        Html::el('div', ['class' => ['empty', $nodes ? 'hide' : null], 'data-empty' => true], 'Nothing matches.'));
};
