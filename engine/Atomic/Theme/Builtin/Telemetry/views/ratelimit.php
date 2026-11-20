<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\RateKey;
use Engine\Atomic\Telemetry\Data\RateStrategy;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, RateKey ...$keys): void {
    $limited = count(array_filter($keys, static fn (RateKey $key): bool => $key->is_limited()));
    $blocked_last_hour = array_sum(array_map(static fn (RateKey $key): int => $key->blocked_hour, $keys));
    $with_strategy = static fn (RateStrategy $strategy): int
        => count(array_filter($keys, static fn (RateKey $key): bool => $key->strategy === $strategy));

    $row = static fn (RateKey $key): Html => $ui->row(
        id: $key->key,
        cells: [
            $ui->cell('col-st', $ui->status($key->is_limited()
                ? new Status('ban', Tone::Bad, 'Limited')
                : new Status('check', Tone::Neutral, 'OK'))),
            $ui->main($key->key, mono: true),
            $ui->cell('rate-str cell', $key->strategy->label()),
            $ui->cell('quota-bal', Html::join(
                $ui->meter($key->usage(), $key->is_limited() ? Tone::Bad : Tone::Neutral),
                Html::el('div', ['class' => 'row-sub mono'],
                    $key->used . ' / ' . $key->limit . ($key->strategy === RateStrategy::Concurrency ? ' active' : '')),
            )),
            $ui->cell('quota-ren cell', $key->reset_in ? Fmt::age($key->reset_in) : $ui->muted('-')),
            $ui->cell($key->blocked_hour ? 'quota-den cell danger' : 'quota-den cell', $key->blocked_hour ?: $ui->muted('-')),
        ],
        filters: [$key->is_limited() ? 'limited' : null, $key->strategy->value],
        search: [$key->key],
    );

    usort($keys, static fn (RateKey $a, RateKey $b): int
        => [$b->is_limited(), $b->usage()] <=> [$a->is_limited(), $a->usage()]);

    echo $ui->badge('rl-badge', $limited);
    echo $ui->list_page(
        summary: $ui->summary(count($keys) . ' keys blocked in 24h',
            $ui->counted_part('limited', $limited, 'limited now', alert: true),
            $ui->part($blocked_last_hour . ' requests blocked in the last hour'),
        ),
        pills: [
            new Pill('all', 'All', count($keys)),
            new Pill('limited', 'Limited', $limited, hot: true),
            ...array_map(static fn (RateStrategy $strategy): Pill
                => new Pill($strategy->value, $strategy->short_label(), $with_strategy($strategy)), RateStrategy::cases()),
        ],
        search_placeholder: 'Search keys',
        columns: [
            ['col-st', ''], ['col-main', 'Key'], ['rate-str', 'Strategy'],
            ['quota-bal', 'Usage'], ['quota-ren', 'Resets'], ['quota-den', 'Blocked 1h'],
        ],
        rows: array_map($row, $keys),
        empty: $keys ? 'No keys match.' : 'No request was blocked in the last 24 hours.',
        foot: Html::join('Showing ', $ui->visible_count(count($keys)), ' of ' . count($keys) . ' keys'),
        drawer: false,
    );
};
