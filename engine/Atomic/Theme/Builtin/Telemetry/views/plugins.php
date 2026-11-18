<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\PluginDependency;
use Engine\Atomic\Telemetry\Data\PluginInfo;
use Engine\Atomic\Telemetry\Data\PluginRequirement;
use Engine\Atomic\Telemetry\Data\PluginState;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, PluginInfo ...$plugins): void {
    $filters = static fn (PluginInfo $plugin): array => array_keys(array_filter([
        'active' => $plugin->state === PluginState::Booted,
        'problem' => $plugin->state->is_problem(),
        'disabled' => $plugin->state === PluginState::Disabled,
    ]));
    $count = static fn (string $filter): int => count(array_filter($plugins, static fn (PluginInfo $plugin): bool => in_array($filter, $filters($plugin), true)));
    $problems = $count('problem');

    $kicker = static fn (PluginInfo $plugin): Html => Html::join($ui->status($plugin->state->status()), Html::el('b', [], $plugin->state->label()));
    $none = static fn (string $text): Html => Html::el('div', ['class' => 'block muted'], $text);

    $drawer = static fn (PluginInfo $plugin): Html => Html::join(
        $ui->head(title: $plugin->name, kicker: $kicker($plugin), sub: $ui->mono($plugin->class)),
        $ui->facts(
            $ui->fact('Version', $plugin->version),
            $ui->fact('Load order', (string)($plugin->load_order ?: '-')),
            $ui->fact('Migration source', Html::join(
                $ui->mono($plugin->migration_source),
                $plugin->has_migrations ? null : [' ', $ui->muted('(no migrations)')],
            ), wide: true),
        ),
        $plugin->error === null ? null : $ui->error_box($plugin->error),
        $ui->label('Lifecycle'),
        $ui->timeline(
            $ui->step($plugin->construct->tone(), 'Construct', $plugin->construct->text()),
            $ui->step($plugin->register->tone(), 'Register', $plugin->register->text()),
            $ui->step($plugin->boot->tone(), 'Boot', $plugin->boot->text()),
        ),
        $ui->label('Depends on'),
        $plugin->deps
            ? $ui->kv_list(...array_map(static fn (PluginDependency $dep): Html => $ui->kv(
                $dep->name,
                $dep->state->label(),
                bad: $dep->state !== PluginState::Booted,
            ), $plugin->deps))
            : $none('Nothing.'),
        $ui->label('Runtime packages'),
        $plugin->requirements
            ? $ui->kv_list(...array_map(static fn (PluginRequirement $req): Html => $ui->kv(
                $req->package,
                $req->missing ? 'Missing' : 'Installed',
                bad: $req->missing,
                key_mono: true,
            ), $plugin->requirements))
            : $none('None declared.'),
    );

    $row = static fn (PluginInfo $plugin): Html => $ui->row(
        id: $plugin->name,
        cells: [
            $ui->cell('plug-ord muted mono', (string)($plugin->load_order ?: '')),
            $ui->cell('col-st', $ui->status($plugin->state->status())),
            $ui->main($plugin->name, $plugin->class, sub_mono: true),
            $ui->cell('plug-ver cell mono', $plugin->version),
            $ui->cell($plugin->state === PluginState::Failed ? 'plug-sta cell danger' : 'plug-sta cell', $plugin->state->label()),
        ],
        filters: $filters($plugin),
        search: [$plugin->name, $plugin->class],
        drawer: $drawer($plugin),
    );

    echo $ui->badge('pl-badge', $problems);
    echo $ui->list_page(
        summary: $ui->summary(count($plugins) . ' plugins',
            $ui->counted_part('active', $count('active'), 'running'),
            $ui->counted_part('problem', $problems, 'with problems', alert: true),
            $ui->counted_part('disabled', $count('disabled'), 'disabled'),
            $ui->part('numbered in load order'),
        ),
        pills: [
            new Pill('all', 'All', count($plugins)),
            new Pill('active', 'Running', $count('active')),
            new Pill('problem', 'Problems', $problems, hot: true),
            new Pill('disabled', 'Disabled', $count('disabled')),
        ],
        search_placeholder: 'Search plugins',
        columns: [['plug-ord', '#'], ['col-st', ''], ['col-main', 'Plugin'], ['plug-ver', 'Version'], ['plug-sta', 'Status']],
        rows: array_map($row, $plugins),
        empty: 'No plugins match.',
        foot: Html::join('Showing ', $ui->visible_count(count($plugins)), ' of ' . count($plugins) . ' plugins'),
    );
};
