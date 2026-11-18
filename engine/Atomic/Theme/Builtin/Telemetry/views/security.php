<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\Lockout;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Data\SecurityReport;
use Engine\Atomic\Telemetry\Data\UserSession;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

/* Rows are keyed by position: session ids are masked, so they can't tell rows apart. */
return static function (Ui $ui, SecurityReport $report, Range $range): void {
    $ago = static fn (?int $seconds): string => $seconds === null ? '-' : Fmt::age($seconds) . ' ago';
    $locked = $report->locked();
    $policy = $report->policy;

    $overview = Html::el('div', ['class' => 'overview'],
        Html::el('div', [],
            $ui->label('Failed logins, ' . $range->text()),
            $ui->big(array_sum($report->failed), ' failed · ' . array_sum($report->lock_events) . ' lockouts'),
            $ui->chart($report->failed, $range, height: 130, unit: ' failed', marks: $report->lock_events, mark_label: 'lockouts'),
            Html::el('div', ['class' => 'chart-note'], 'Red marks show hours with a lockout.')),
        Html::el('div', [], $ui->label('Most failed identifiers'), $ui->hbars($report->top_failed)),
        Html::el('div', [], $ui->label('Policy'), array_map(
            static fn (string $label, string $value): Html => $ui->kv($label, $value, mono: true),
            array_keys($policy->rules()), $policy->rules())),
    );

    $sessions = $report->sessions;
    usort($sessions, static fn (UserSession $a, UserSession $b): int => $a->last_ago <=> $b->last_ago);
    $session_drawer = static fn (UserSession $session): Html => Html::join(
        $ui->head(title: $session->user ?? '', kicker: $session->current ? 'Your session' : 'Session'),
        $session->impersonating === null ? null : $ui->note(Html::join('Impersonated by ', Html::el('b', [], $session->impersonating), '.')),
        $ui->facts(
            $ui->fact('IP', $session->ip, mono: true),
            $ui->fact('Device', $session->agent),
            $ui->fact('Started', $ago($session->created_ago)),
            $ui->fact('Last active', $ago($session->last_ago)),
        ),
    );
    $session_row = static fn (UserSession $session, int $index): Html => $ui->row(
        id: 's' . $index,
        cells: [
            $ui->cell('col-st', $ui->status($session->status())),
            $ui->main(Html::join($session->user, $session->current ? Html::join(' ', $ui->muted('(you)')) : null), $session->short_id(), sub_mono: true),
            $ui->cell('sec-ip cell mono', $session->ip),
            $ui->cell('sec-ag cell', $session->agent),
            $ui->cell('sec-la cell', $ago($session->last_ago)),
        ],
        filters: ['sessions'],
        search: [$session->user, $session->ip, $session->agent],
        drawer: $session_drawer($session),
    );

    $lockouts = $report->lockouts;
    usort($lockouts, static fn (Lockout $a, Lockout $b): int => [$b->locked, $b->count] <=> [$a->locked, $a->count]);
    $lockout_drawer = static fn (Lockout $lockout): Html => Html::join(
        $ui->head(title: $lockout->identifier, kicker: $lockout->locked ? 'Locked' : 'Failed attempts', mono: true),
        $ui->facts(
            $ui->fact('Attempts', $lockout->count . ' of ' . $lockout->max),
            $ui->fact('First attempt', $ago($lockout->first_ago)),
            $ui->fact('Window', Fmt::age($policy->window)),
            $ui->fact($lockout->locked ? 'Unlocks in' : 'Lockout length', Fmt::age($lockout->locked ? $lockout->remaining : $policy->lockout)),
        ),
        $lockout->locked ? $ui->p('Further attempts are rejected until the lock expires.') : null,
    );
    $lockout_row = static fn (Lockout $lockout, int $index): Html => $ui->row(
        id: 'l' . $index,
        cells: [
            $ui->cell('col-st', $ui->status($lockout->status())),
            $ui->main($lockout->identifier, mono: true),
            $ui->cell('quota-bal', Html::join(
                $ui->meter($lockout->usage(), $lockout->tone()),
                Html::el('div', ['class' => 'row-sub mono'], $lockout->count . ' / ' . $lockout->max))),
            $ui->cell($lockout->locked ? 'sec-la cell danger' : 'sec-la cell', $lockout->locked ? 'locked ' . Fmt::age($lockout->remaining) : 'counting'),
        ],
        filters: ['lockouts'],
        search: [$lockout->identifier],
        drawer: $lockout_drawer($lockout),
    );

    echo $ui->badge('sy-badge', $locked);
    echo $ui->list_page(
        summary: $ui->summary(count($sessions) . ' active sessions',
            $locked ? $ui->part($ui->danger($locked . ' locked')) : null,
            $ui->part(count($lockouts) . ' identifiers with recent failures'),
            $report->impersonation() ? $ui->part('impersonation active') : null,
        ),
        extras: Html::join($ui->lifetime($report->lifetime), $overview),
        pills: [
            new Pill('sessions', 'Sessions', count($sessions)),
            new Pill('lockouts', 'Failed logins and lockouts', count($lockouts), hot: $locked > 0),
        ],
        search_placeholder: 'Search',
        view: 'sessions',
        views: true,
        lists: Html::join(
            $ui->list(
                columns: [['col-st', ''], ['col-main', 'User'], ['sec-ip', 'IP'], ['sec-ag', 'Device'], ['sec-la', 'Last active']],
                rows: array_map($session_row, $sessions, array_keys($sessions)),
                empty: 'No sessions match.',
                foot: Html::join($ui->visible_count(count($sessions)), ' of ' . count($sessions) . ' sessions'),
                when: 'sessions',
            ),
            $ui->list(
                columns: [['col-st', ''], ['col-main', 'Identifier'], ['quota-bal', 'Attempts'], ['sec-la', 'Status']],
                rows: array_map($lockout_row, $lockouts, array_keys($lockouts)),
                empty: 'Nothing to show.',
                foot: Html::join($ui->visible_count(count($lockouts)), ' identifiers'),
                when: 'lockouts',
                shown: false,
            ),
        ),
    );
};
