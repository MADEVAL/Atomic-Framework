<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\DenialReason;
use Engine\Atomic\Telemetry\Data\PacingWindow;
use Engine\Atomic\Telemetry\Data\QuotaDenial;
use Engine\Atomic\Telemetry\Data\QuotaReport;
use Engine\Atomic\Telemetry\Data\QuotaScope;
use Engine\Atomic\Telemetry\Data\QuotaTier;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, QuotaReport $report, Range $range): void {
    $scopes = $report->scopes;
    $denied = array_sum(array_map(static fn (QuotaScope $scope): int => $scope->denied_total(), $scopes));
    $with_denials = count(array_filter($scopes, static fn (QuotaScope $scope): bool => $scope->denied_total() > 0));

    $overview = Html::join(
        $ui->lifetime($report->lifetime),
        Html::el('div', ['class' => 'overview'],
            Html::el('div', [],
                $ui->label('Denied requests, ' . $range->text()),
                $ui->big(Fmt::n(array_sum($report->denied())), ' denied'),
                $ui->chart($report->denied(), $range, height: 150, unit: ' denied')),
            Html::el('div', [], $ui->label('Denials by reason'), $ui->hbars($report->by_reason()))),
    );

    $pacing_row = static fn (PacingWindow $pace): Html => Html::el('div', ['class' => 'quota-pace'],
        $ui->mono($pace->name()),
        $ui->meter($pace->usage(), $pace->is_full() ? Tone::Bad : Tone::Neutral),
        Html::el('span', ['class' => 'mono muted'], $pace->used . '/' . $pace->text()));

    $drawer = static function (QuotaScope $scope) use ($ui, $report, $range, $pacing_row): Html {
        $operations = $report->tier($scope->tier)?->operations ?? [];

        return Html::join(
            $ui->head(title: $scope->scope, kicker: $scope->tier . ' plan', mono: true),
            $ui->big(Fmt::n($scope->balance), ' of ' . Fmt::n($scope->credits) . ' credits', 'mt-2'),
            $ui->meter($scope->share()),
            $ui->facts($ui->fact('Renews in', Fmt::age($scope->ttl))),
            $ui->label('Denied, ' . $range->text() . ' · ' . Fmt::n(array_sum($scope->denied))),
            Html::el('div', ['class' => 'block'], $ui->chart($scope->denied, $range, height: 90, unit: ' denied')),
            $ui->label('Pacing windows'),
            Html::el('div', ['class' => 'block'], array_map($pacing_row, $scope->pacing)),
            $ui->label('Operation cost'),
            $ui->kv_list(...array_map(static fn (string $name, int $cost): Html => $ui->kv($name, $cost . ' credits', key_mono: true),
                array_keys($operations), $operations)),
            $ui->label('Recent denials'),
            $scope->recent
                ? $ui->kv_list(...array_map(static fn (QuotaDenial $denial): Html => $ui->kv(
                    $denial->time . ' · ' . $denial->operation,
                    DenialReason::label_of($denial->reason),
                    bad: $denial->reason === DenialReason::InsufficientBalance->value,
                    key_mono: true,
                ), $scope->recent))
                : Html::el('div', ['class' => 'muted'], 'None.'),
        );
    };

    $row = static fn (QuotaScope $scope): Html => $ui->row(
        id: $scope->scope,
        cells: [
            $ui->cell('col-st', $ui->status(new Status('check', Tone::Neutral, 'Balance'))),
            $ui->main($scope->scope, $scope->tier, mono: true),
            $ui->cell('quota-bal', Html::join(
                $ui->meter($scope->share()),
                Html::el('div', ['class' => 'row-sub mono'], Fmt::n($scope->balance) . ' / ' . Fmt::n($scope->credits)))),
            $ui->cell('quota-ren cell', Fmt::age($scope->ttl)),
            $ui->cell($scope->denied_total() ? 'quota-den cell danger' : 'quota-den cell', $scope->denied_total() ?: ''),
        ],
        filters: [$scope->denied_total() > 0 ? 'denied' : null],
        search: [$scope->scope, $scope->tier],
        drawer: $drawer($scope),
    );

    usort($scopes, static fn (QuotaScope $a, QuotaScope $b): int => $a->share() <=> $b->share());

    $plan = static fn (QuotaTier $tier): Html => Html::el('div', ['class' => 'quota-plan'],
        Html::el('b', [], $tier->name),
        Html::el('span', ['class' => 'muted'], Fmt::n($tier->credits) . ' credits / ' . $tier->days() . ' days · ' . $tier->pacing_text()),
        Html::el('span', ['class' => 'mono ops'], $tier->operations_text()));

    echo $ui->list_page(
        summary: $ui->summary(count($scopes) . ' scopes', $ui->part($denied ? $ui->danger($denied . ' denied') : '0 denied')),
        extras: $overview,
        pills: [
            new Pill('all', 'All', count($scopes)),
            new Pill('denied', 'Denied', $with_denials, hot: true),
        ],
        search_placeholder: 'Search scope or tier',
        columns: [['col-st', ''], ['col-main', 'Scope'], ['quota-bal', 'Balance'], ['quota-ren', 'Renews'], ['quota-den', 'Denied']],
        rows: array_map($row, $scopes),
        empty: 'No scopes match.',
        foot: Html::join('Showing ', $ui->visible_count(count($scopes)), ' of ' . count($scopes) . ' scopes'),
        after: Html::el('div', ['class' => 'quota-plans'], $ui->label('Plans'), array_map($plan, $report->tiers)),
    );
};
