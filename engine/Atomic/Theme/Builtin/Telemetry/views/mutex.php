<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\LockInfo;
use Engine\Atomic\Telemetry\Data\MutexDriverInfo;
use Engine\Atomic\Telemetry\Data\MutexReport;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, MutexReport $report): void {
    $locks = $report->locks;
    $expired = count($report->expired());

    $held_for = static fn (LockInfo $lock): Html => $lock->age === null ? $ui->muted('-') : Html::join(Fmt::age($lock->age));
    $ttl = static fn (LockInfo $lock): Html => $lock->ttl === null ? $ui->muted('unknown') : Html::join(Fmt::age($lock->ttl));
    $expiry = static fn (LockInfo $lock): string => $lock->is_expired()
        ? 'expired ' . Fmt::age(-$lock->remaining) . ' ago'
        : Fmt::age($lock->remaining) . ($lock->ttl ? ' of ' . Fmt::age($lock->ttl) : ' left');
    $status = static fn (LockInfo $lock): Status => $lock->is_expired()
        ? new Status('alert', Tone::Bad, 'Expired')
        : new Status('check', Tone::Neutral, 'Held');

    $drawer = static fn (LockInfo $lock): Html => Html::join(
        $ui->head(title: $lock->name, kicker: $lock->is_expired() ? 'Expired, not released' : 'Held', mono: true),
        $ui->facts(
            $ui->fact('Driver', $report->driver),
            $ui->fact('TTL', $ttl($lock)),
            $ui->fact('Held for', $held_for($lock)),
            $lock->is_expired()
                ? $ui->fact('Expired', Fmt::age(-$lock->remaining) . ' ago')
                : $ui->fact('Expires in', Fmt::age($lock->remaining)),
        ),
        $ui->p($lock->is_expired()
            ? 'The lock passed its TTL. A new acquire replaces it, but it still shows here until then.'
            : 'Held by a running process.'),
    );

    $row = static fn (LockInfo $lock): Html => $ui->row(
        id: $lock->name,
        cells: [
            $ui->cell('col-st', $ui->status($status($lock))),
            $ui->main($lock->name, mono: true),
            $ui->cell('mutex-held cell', $held_for($lock)),
            $ui->cell('quota-bal', Html::join(
                $lock->ttl ? $ui->meter(max(0, $lock->remaining) / $lock->ttl, $lock->is_expired() ? Tone::Bad : Tone::Neutral) : null,
                Html::el('div', ['class' => 'row-sub mono'], $expiry($lock)),
            )),
        ],
        filters: [$lock->is_expired() ? 'expired' : 'held'],
        search: [$lock->name],
        drawer: $drawer($lock),
    );

    usort($locks, static fn (LockInfo $a, LockInfo $b): int
        => [!$a->is_expired(), $a->remaining] <=> [!$b->is_expired(), $b->remaining]);

    $known_age = array_filter($locks, static fn (LockInfo $lock): bool => $lock->age !== null);
    $longest = $known_age ? max(array_map(static fn (LockInfo $lock): int => (int)$lock->age, $known_age)) : 0;

    echo $ui->badge('mx-badge', $expired);
    echo $ui->list_page(
        summary: $ui->summary(count($locks) . ' locks',
            $ui->counted_part('expired', $expired, 'expired but not released', alert: true),
            $ui->part($known_age ? 'longest held ' . Fmt::age($longest) : 'driver ' . ($report->driver ?: 'none')),
        ),
        extras: Html::el('div', ['class' => 'drivers'], array_map(static fn (MutexDriverInfo $driver): Html => $ui->driver(
            $driver->name,
            $driver->available ? 'available' : 'unavailable',
            $driver->available ? Tone::Neutral : Tone::Mute,
            $driver->active,
        ), $report->drivers)),
        pills: [
            new Pill('all', 'All', count($locks)),
            new Pill('held', 'Held', count($locks) - $expired),
            new Pill('expired', 'Expired', $expired, hot: true),
        ],
        search_placeholder: 'Search locks',
        columns: [['col-st', ''], ['col-main', 'Lock'], ['mutex-held', 'Held for'], ['quota-bal', 'Time to expiry']],
        rows: array_map($row, $locks),
        empty: 'No locks match.',
        foot: Html::join('Showing ', $ui->visible_count(count($locks)), ' of ' . count($locks) . ' locks'),
    );
};
