<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\HiveNode;
use Engine\Atomic\Telemetry\Data\JobOutcome;
use Engine\Atomic\Telemetry\Data\JobRun;
use Engine\Atomic\Telemetry\Data\Lifetime;
use Engine\Atomic\Telemetry\Data\LogLine;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Tone;

/** Every method returns safe Html. Every copy button is copy(), always the last item of its row. */
final class Ui
{
    public function e(string|int|float|null $text): string
    {
        return $text === null ? '' : Html::escape($text);
    }

    public function muted(string|int|Html $text): Html
    {
        return Html::el('span', ['class' => 'muted'], $text);
    }

    public function mono(string|int|Html|null $text): Html
    {
        return Html::el('span', ['class' => 'mono'], $text);
    }

    public function danger(string|int|Html $text): Html
    {
        return Html::el('span', ['class' => 'danger'], $text);
    }

    public function tag(string $text): Html
    {
        return Html::el('span', ['class' => 'tag'], $text);
    }

    public function label(string $text): Html
    {
        return Html::el('div', ['class' => 'label'], $text);
    }

    public function p(string|Html $content): Html
    {
        return Html::el('p', [], $content);
    }

    public function empty(string $text): Html
    {
        return Html::el('div', ['class' => 'empty'], $text);
    }

    public function failed(string $message): Html
    {
        return Html::el('div', ['class' => 'failed'],
            $this->icon('alert'),
            Html::el('div', [],
                Html::el('b', [], $message),
                Html::el('button', ['class' => 'btn', 'data-retry' => true], Html::el('span', [], 'Retry'))));
    }

    public function note(string|Html $content, ?Html $end = null): Html
    {
        return Html::el('div', ['class' => 'note'],
            $this->icon('alert'),
            Html::el('div', ['class' => 'note-body'], $content),
            $end);
    }

    public function button(string $label, ?string $icon = null, array $attrs = [], bool $on = false): Html
    {
        return Html::el('button', ['class' => ['btn', $on ? 'is-on' : null]] + $attrs,
            $icon === null ? null : $this->icon($icon),
            Html::el('span', [], $label));
    }

    public function modal_attrs(string $url): array
    {
        return ['data-modal' => $url];
    }

    public function modal(Html $head, ?Html ...$body): Html
    {
        return Html::el('div', ['class' => 'modal-in'], $head, $body);
    }

    public function icon(string $name, ?string $class = null): Html
    {
        return Html::el('svg', ['class' => ['ic', $class], 'aria-hidden' => 'true'], Html::el('use', ['href' => '#i-' . $name]));
    }

    public function icon_button(string $icon, string $title, array $attrs = []): Html
    {
        return Html::el('button', ['class' => 'btn-icon', 'title' => $title, 'aria-label' => $title] + $attrs, $this->icon($icon));
    }

    public function copy(string|int $text, string $title = 'Copy'): Html
    {
        return $this->icon_button('copy', $title, ['data-copy' => (string)$text]);
    }

    public function close(): Html
    {
        return $this->icon_button('x', 'Close (Esc)', ['data-close' => true]);
    }

    public function actions(?Html ...$items): ?Html
    {
        $present = array_filter($items);
        return $present ? Html::el('span', ['class' => 'actions'], $present) : null;
    }

    public function status(Status $status, bool $small = true, bool $spin = false): Html
    {
        return Html::el('span', [
            'class' => ['status', $small ? 'status-sm' : null, $spin ? 'is-spin' : null],
            'data-tone' => self::tone($status->tone),
            'title' => $status->label === '' ? null : $status->label,
        ], $this->icon($status->icon));
    }

    public function summary(string $headline, ?Html ...$details): Html
    {
        return Html::el('div', ['class' => 'summary'],
            Html::el('b', [], $headline),
            Html::el('span', ['class' => 'muted dotted'], $details));
    }

    public function part(string|int|Html ...$content): Html
    {
        return Html::el('span', [], $content);
    }

    public function counted_part(string $filter, int $count, string $text, bool $alert = false): Html
    {
        return Html::el('span', [
            'class' => [$alert ? 'danger' : null, $alert && !$count ? 'hide' : null],
            'data-hide-zero' => $alert ? $filter : null,
        ], Html::el('span', ['data-count' => $filter], $count), ' ' . $text);
    }

    /* ── List page ──
       Filtering and search run in the browser over rendered rows:
         [data-list]          the page; server.js keeps its filter, search and selection
         [data-filter=key]    a pill; [data-count=key] shows how many rows match it
         [data-search]        the search field
         [data-row=id]        a row: data-keys lists the filters it matches,
                              data-text is what the search looks at,
                              its <template> holds the drawer content
         [data-empty]         shown when no row of its list is visible
         [data-default=key]   the pill on when the page opens (else "all")
         [data-views]         the pills switch between lists instead of filtering one
         [data-when=key]      shown only while pill key is on (a list, a summary)
         [data-select=id]     selects row id, leaving a filter that hides it */

    /**
     * @param list<Pill> $pills
     * @param list<array{0:string, 1:string}> $columns [class, label]
     * @param list<Html> $rows
     */
    public function list_page(
        array $pills,
        string $search_placeholder,
        array $columns = [],
        array $rows = [],
        string $empty = '',
        Html|string|null $foot = null,
        ?Html $summary = null,
        ?Html $extras = null,
        bool $drawer = true,
        ?Html $after = null,
        ?Html $lists = null,
        ?string $view = null,
        bool $views = false,
    ): Html {
        $list = $lists ?? $this->list($columns, $rows, $empty, $foot);

        return Html::el('div', ['data-list' => true, 'data-default' => $view, 'data-views' => $views],
            $summary,
            $extras,
            Html::el('div', ['class' => 'toolbar'],
                Html::el('div', ['class' => 'pills'], array_map(fn (Pill $pill): Html => $this->pill($pill, $view ?? 'all'), $pills)),
                Html::el('div', ['class' => 'toolbar-end'],
                    Html::el('input', ['class' => 'input', 'placeholder' => $search_placeholder, 'data-search' => true]))),
            $drawer ? Html::el('div', ['class' => 'split'], $list, Html::el('aside', ['class' => 'drawer hide'])) : $list,
            $after);
    }

    /**
     * With views: true the pills switch lists: one list per pill, each with $when set to its pill key.
     *
     * @param list<array{0:string, 1:string}> $columns [class, label]
     * @param list<Html> $rows
     */
    public function list(array $columns, array $rows, string $empty, Html|string|null $foot, ?string $when = null, bool $shown = true): Html
    {
        return Html::el('div', ['class' => ['list', $shown ? null : 'hide'], 'data-when' => $when],
            Html::el('div', ['class' => 'list-head'], array_map(
                static fn (array $column): Html => Html::el('span', ['class' => $column[0]], $column[1]),
                $columns,
            )),
            Html::el('div', [], $rows, Html::el('div', ['class' => ['empty', $rows ? 'hide' : null], 'data-empty' => true], $empty)),
            $foot === null ? null : Html::el('div', ['class' => 'list-foot'], Html::el('span', [], $foot)));
    }

    /** @param list<Pill> $pills */
    public function pills(array $pills, string $current = 'all'): Html
    {
        return Html::el('div', ['class' => 'pills'], array_map(fn (Pill $pill): Html => $this->pill($pill, $current), $pills));
    }

    /* ── Log lines ──
       A line is a row whose <template> opens inline under it ([data-inline] page):
       the full message and its hive dump. It opens only when that shows something
       new; server.js marks such rows .can-open. */

    /** @param int $occurrence how many identical lines come before this one, so row ids stay unique */
    public function log_line(LogLine $line, Links $links, int $occurrence = 0): Html
    {
        $message = static fn (): Html => Html::join(array_map(
            static fn (string $part, int $i): Html|string => $i % 2 ? Html::el('strong', [], $part) : $part,
            $line->parts(), array_keys($line->parts())));

        return Html::el('div', [
            'class' => ['log-line', $line->is_error() ? 'is-err' : null],
            'data-row' => $line->key() . '.' . $occurrence,
            'data-key' => $line->key(),
            'data-keys' => 'all ' . $line->filter(),
            'data-text' => mb_strtolower($line->message),
            'data-ts' => (string)$line->ts,
            'data-dump' => $line->dump_id !== null,
            'data-multiline' => $line->is_multiline(),
        ],
            Html::el('div', ['class' => 'r'],
                Html::el('span', ['class' => 'chev'], $this->icon('chevron')),
                Html::el('span', ['class' => 'ts mono'], $line->time()),
                $this->status($line->status()),
                Html::el('span', ['class' => 'log-ch'], $line->channel),
                Html::el('span', ['class' => 'msg mono'], $message()),
                $this->copy($line->text(), 'Copy this line')),
            // .code is left out while lines are wrapped: they show the whole message already.
            Html::el('template', [], Html::el('div', ['class' => 'more'],
                $this->code('Message', $line->message, wrap: true, tall: true, highlighted: $message()),
                $line->dump_id === null ? null : $this->kv('Hive dump', $line->dump_id,
                    mono: true,
                    copy: $line->dump_id,
                    copy_title: 'Copy dump id',
                    actions: $this->icon_button('eye', 'View dump', $this->modal_attrs($links->part('dump', ['id' => $line->dump_id])))))));
    }

    public function log_more(?string $url): Html
    {
        return Html::el('div', ['data-more' => $url]);
    }

    private function pill(Pill $pill, string $current): Html
    {
        return Html::el('button', ['class' => ['pill', $pill->key === $current ? 'is-on' : null], 'data-filter' => $pill->key],
            $pill->label,
            Html::el('span', [
                'class' => ['count', $pill->hot && ($pill->total ?? $pill->count) ? 'is-hot' : null],
                'data-count' => $pill->fixed ? null : $pill->key,
                'data-hot' => $pill->hot,
                'data-total' => $pill->total,
            ], $pill->total ?? $pill->count));
    }

    public function visible_count(int $count): Html
    {
        return Html::el('span', ['data-count' => '@visible'], $count);
    }

    /**
     * @param list<Html|null> $cells
     * @param list<string|null> $filters filter keys this row matches ('all' is implied; nulls are skipped)
     * @param list<string|null> $search text the search field matches against
     */
    public function row(
        string $id,
        array $cells,
        array $filters,
        array $search,
        ?Html $drawer = null,
        bool $off = false,
        ?string $group = null,
    ): Html {
        return Html::el('div', [
            'class' => ['row', $off ? 'is-off' : null, $drawer === null ? 'is-static' : null],
            'data-group' => $group,
            'data-row' => $id,
            'data-keys' => implode(' ', ['all', ...array_filter($filters)]),
            'data-text' => mb_strtolower(implode(' ', array_filter($search, static fn (?string $text): bool => $text !== null))),
        ], $cells, $drawer === null ? null : Html::el('template', [], $drawer));
    }

    public function main(
        string|Html $title,
        string|Html|null $sub = null,
        bool $mono = false,
        bool $sub_mono = false,
        bool $sub_bad = false,
        ?string $title_class = null,
    ): Html {
        return Html::el('span', ['class' => 'col-main'],
            Html::el('div', ['class' => ['row-title', $mono ? 'mono' : null, $title_class]], $title),
            $sub === null || $sub === '' ? null : Html::el('div', [
                'class' => ['row-sub', $sub_mono ? 'mono' : null, $sub_bad ? 'danger' : null],
            ], $sub));
    }

    /** Hides while none of its rows is visible; $count shows how many are. */
    public function group(string $key, string|Html $title, string $when, int $count): Html
    {
        return Html::el('div', ['class' => 'list-group', 'data-group-head' => $key],
            Html::el('b', [], $title),
            Html::el('span', ['class' => 'muted'], $when . ' · ', Html::el('span', ['data-count' => '@group'], $count)));
    }

    public function cell(string $class, string|int|Html|null $content, ?string $title = null): Html
    {
        return Html::el('span', ['class' => $class, 'title' => $title], $content);
    }

    public function head(
        string|Html $title,
        string|Html|null $kicker = null,
        string|Html|null $sub = null,
        bool $mono = false,
        ?Html $actions = null,
    ): Html {
        return Html::el('div', ['class' => 'dhead'],
            Html::el('div', ['class' => 'dhead-body'],
                $kicker === null ? null : Html::el('div', ['class' => 'dhead-kicker'], $kicker),
                Html::el('h3', ['class' => $mono ? 'mono' : null], $title),
                $sub === null ? null : Html::el('div', ['class' => 'dhead-sub'], $sub)),
            $this->actions($actions, $this->close()));
    }

    public function facts(?Html ...$facts): Html
    {
        return Html::el('div', ['class' => 'facts'], $facts);
    }

    public function fact(
        string $label,
        string|int|Html|null $value,
        bool $wide = false,
        bool $mono = false,
        ?string $copy = null,
        ?string $copy_title = null,
    ): Html {
        return Html::el('div', ['class' => ['fact', $wide ? 'is-wide' : null]],
            Html::el('div', ['class' => 'fact-body'],
                Html::el('small', [], $label),
                Html::el('span', ['class' => $mono ? 'mono' : null], $value)),
            $copy === null ? null : $this->copy($copy, $copy_title ?? 'Copy'));
    }

    public function timeline(Html ...$steps): Html
    {
        return Html::el('div', ['class' => 'timeline'], $steps);
    }

    public function step(Tone $tone, string|Html $title, string|Html|null $sub = null, bool $mono = false): Html
    {
        return Html::el('div', ['class' => 'step'],
            Html::el('i', ['data-tone' => self::tone($tone)]),
            Html::el('div', [],
                Html::el('b', [], $title),
                Html::el('small', ['class' => $mono ? 'mono' : null], $sub)));
    }

    public function section_head(string $title, ?Html $actions = null, ?string $sub = null): Html
    {
        return Html::el('div', ['class' => 'sec-h'],
            Html::el('span', ['class' => 'label'], $title),
            $sub === null ? null : Html::el('span', ['class' => 'sub'], $sub),
            $this->actions($actions));
    }

    public function code(
        string $title,
        string $text,
        bool $wrap = false,
        bool $tall = false,
        ?string $sub = null,
        ?Html $highlighted = null,
        ?Html $actions = null,
    ): Html {
        return Html::el('div', ['class' => ['code', $wrap ? 'is-wrap' : null, $tall ? 'is-tall' : null]],
            $this->section_head($title, Html::join($actions, $this->copy($text, 'Copy ' . strtolower($title))), $sub),
            Html::el('pre', [], $highlighted ?? $text));
    }

    public function kv_list(Html ...$rows): Html
    {
        return Html::el('div', ['class' => 'kv-list'], $rows);
    }

    public function kv(
        string|Html $key,
        string|int|Html $value,
        bool $bad = false,
        bool $key_mono = false,
        bool $mono = false,
        ?string $copy = null,
        ?string $copy_title = null,
        ?Html $actions = null,
    ): Html {
        return Html::el('div', ['class' => ['kv', $bad ? 'is-bad' : null]],
            Html::el('span', ['class' => ['kv-k', $key_mono ? 'mono' : null]], $key),
            Html::el('span', ['class' => ['kv-v', $mono ? 'mono' : null]], $value),
            $actions,
            $copy === null ? null : $this->copy($copy, $copy_title ?? 'Copy'));
    }

    public function error_box(string $message, ?string $location = null): Html
    {
        return Html::el('div', ['class' => 'error-box'],
            Html::el('b', [], $message),
            $location === null ? null : Html::el('div', ['class' => 'mono'], $location));
    }

    public function meter(float $fraction, Tone $tone = Tone::Neutral): Html
    {
        $percent = max(0.0, min(100.0, $fraction * 100));
        return Html::el('span', ['class' => 'meter', 'data-tone' => self::tone($tone), 'vars' => ['w' => self::number($percent) . '%']], Html::el('i'));
    }

    private const CHART_WIDTH = 480;
    private const CHART_HEADROOM = 1.1; // the peak sits a little under the top edge
    private const MARK_WIDTH = 6;
    private const MARK_HEIGHT = 4;
    private const SPARK_WIDTH = 96;
    private const SPARK_HEIGHT = 24;

    /**
     * @param list<int|float> $values
     * @param list<int> $marks per point; zero draws no mark
     */
    public function chart(
        array $values,
        Range $range,
        int $height = 120,
        string $unit = '',
        array $marks = [],
        string $mark_label = 'marked',
    ): Html {
        // An empty series would build an invalid SVG path.
        $series = count($values) >= 2 ? array_values($values) : [0, 0];
        $count = count($series);
        $peak = max(1, ...$series) * self::CHART_HEADROOM;
        $x = static fn (int $i): float => $i / ($count - 1) * self::CHART_WIDTH;
        $y = static fn (int|float $value): float => $height - $value / $peak * ($height - 6) - 2;
        $points = array_map(static fn (int|float $value, int $i): string => self::fixed($x($i)) . ',' . self::fixed($y($value)), $series, array_keys($series));
        $slot = self::CHART_WIDTH / $count;

        $grid = array_map(static fn (float $share): Html => Html::el('line', [
            'class' => 'chart-grid', 'x1' => 0, 'x2' => self::CHART_WIDTH, 'y1' => self::fixed($height * $share), 'y2' => self::fixed($height * $share),
        ]), [1 / 3, 2 / 3]);
        $mark_rects = array_map(static fn (int $i): ?Html => empty($marks[$i]) ? null : Html::el('rect', [
            'class' => 'chart-mark',
            'x' => self::fixed($x($i) - self::MARK_WIDTH / 2),
            'y' => $height - self::MARK_HEIGHT,
            'width' => self::MARK_WIDTH,
            'height' => self::MARK_HEIGHT,
            'rx' => 1,
        ]), array_keys($marks));
        // Invisible full-height slots carry the hover titles.
        $hit_areas = array_map(static fn (int|float $value, int $i): Html => Html::el('rect', [
            'x' => self::fixed($x($i) - $slot / 2), 'y' => 0, 'width' => self::fixed($slot), 'height' => $height, 'fill' => 'transparent',
        ], Html::el('title', [], Fmt::n($value) . $unit . (empty($marks[$i]) ? '' : ', ' . $marks[$i] . ' ' . $mark_label))), $series, array_keys($series));

        return Html::join(
            Html::el('svg', [
                'class' => 'chart',
                'viewBox' => '0 0 ' . self::CHART_WIDTH . ' ' . $height,
                'preserveAspectRatio' => 'none',
                'vars' => ['h' => $height . 'px'],
            ],
                $grid,
                Html::el('path', ['class' => 'chart-area', 'd' => 'M0,' . $height . ' L' . implode(' L', $points) . ' L' . self::CHART_WIDTH . ',' . $height . 'Z']),
                Html::el('path', ['class' => 'chart-line', 'd' => 'M' . implode(' L', $points)]),
                $mark_rects,
                $hit_areas),
            Html::el('div', ['class' => 'chart-axis'], array_map(static fn (string $text): Html => Html::el('span', [], $text), $range->axis())),
        );
    }

    /** @param list<int|float> $values */
    public function spark(array $values): Html
    {
        $values = count($values) >= 2 ? array_values($values) : [0, 0];
        $peak = max(1, ...$values);
        $last = count($values) - 1;
        $points = array_map(static fn (int|float $value, int $i): string => self::fixed($i / $last * self::SPARK_WIDTH) . ','
            . self::fixed(self::SPARK_HEIGHT - 1 - $value / $peak * (self::SPARK_HEIGHT - 3)), $values, array_keys($values));

        return Html::el('svg', ['class' => 'spark', 'viewBox' => '0 0 ' . self::SPARK_WIDTH . ' ' . self::SPARK_HEIGHT, 'preserveAspectRatio' => 'none'],
            Html::el('path', ['class' => 'chart-area', 'd' => 'M0,' . self::SPARK_HEIGHT . ' L' . implode(' L', $points) . ' L' . self::SPARK_WIDTH . ',' . self::SPARK_HEIGHT . 'Z']),
            Html::el('path', ['class' => 'chart-line', 'd' => 'M' . implode(' L', $points)]));
    }

    /** @param array<string|int, int> $counts label => count, in tie order */
    public function hbars(array $counts): Html
    {
        $counts = array_filter($counts);
        if (!$counts) {
            return Html::el('div', ['class' => 'muted'], 'None.');
        }
        $labels = array_keys($counts);
        usort($labels, static fn (string|int $a, string|int $b): int => $counts[$b] <=> $counts[$a]);
        $peak = max(1, ...array_values($counts));

        return Html::join(array_map(fn (string|int $label): Html => Html::el('div', ['class' => 'hbar'],
            Html::el('span', ['class' => 'mono'], (string)$label),
            $this->meter($counts[$label] / $peak),
            Html::el('b', [], Fmt::n($counts[$label]))), $labels));
    }

    public function lifetime(Lifetime $lifetime): Html
    {
        $counters = [];
        foreach ($lifetime->labels() as $label => $count) {
            $counters[] = Html::el('span', [], Html::el('b', [], Fmt::n($count)), ' ' . $label);
        }

        return Html::el('div', ['class' => 'lifetime'], Html::el('span', ['class' => 'label mb-0'], 'All-time'), $counters);
    }

    public function big(string|int $value, string $unit, ?string $class = null): Html
    {
        return Html::el('div', ['class' => ['big', $class]], $value, Html::el('small', [], $unit));
    }

    private static function fixed(float $value): string
    {
        return sprintf('%.1F', $value);
    }

    private const RUN_BASE_HEIGHT = 6;
    private const RUN_FULL_HEIGHT = 22;

    public function runs(JobRun ...$runs): Html
    {
        return Html::el('span', ['class' => 'runs'], array_map(static fn (JobRun $run): Html => Html::el('i', [
            'class' => 'st-' . $run->status->value,
            'vars' => ['h' => ($run->status === JobOutcome::Ok ? $run->height() + self::RUN_BASE_HEIGHT : self::RUN_FULL_HEIGHT) . 'px'],
        ]), $runs));
    }

    public function driver(string $name, string $note, Tone $tone, bool $active): Html
    {
        $icon = match ($tone) {
            Tone::Bad => 'x',
            Tone::Mute => 'ban',
            default => 'check',
        };

        return Html::el('div', ['class' => 'driver'],
            $this->status(new Status($icon, $tone, '')),
            Html::el('b', [], $name),
            Html::el('span', ['class' => 'muted'], $note),
            $active ? Html::el('span', ['class' => 'driver-on'], 'active') : null);
    }

    /** @param list<array{0:string, 1:string, 2:int, 3:array<string, mixed>}> $items [state, label, count, button attrs] */
    public function legend(array $items): Html
    {
        return Html::el('div', ['class' => 'legend'], array_map(static fn (array $item): Html => Html::el('button', ['class' => 'legend-item'] + $item[3],
            Html::el('i', ['class' => 'st-' . $item[0]]),
            $item[1],
            Html::el('b', [], $item[2])), $items));
    }

    /**
     * Nodes come parents first; a branch folds every deeper node after it. While
     * searching, matching leaves show flat with their full path.
     */
    public function tree_node(HiveNode $node): Html
    {
        $attrs = ['vars' => ['depth' => $node->depth], 'data-node' => $node->path, 'data-depth' => $node->depth];
        if ($node->is_branch()) {
            return Html::el('div', ['class' => 'hive-node is-branch', 'data-branch' => true] + $attrs,
                Html::el('span', ['class' => 'c'], '▾'),
                Html::el('b', [], $node->key),
                Html::el('span', ['class' => 'm'], $node->size));
        }

        return Html::el('div', ['class' => 'hive-node', 'data-key' => $node->key . ':', 'data-full' => $node->path . ':',
            'data-text' => mb_strtolower($node->path . ' ' . $node->value)] + $attrs,
            Html::el('span', ['class' => 'c']),
            Html::el('b', [], $node->key . ':'),
            Html::el('span', ['class' => $node->is_string ? 's' : 'n'], $node->shown_value()));
    }

    public function badge(string $id, int $count): Html
    {
        return Html::el('i', ['class' => 'hide', 'data-badge' => $id, 'data-value' => $count]);
    }

    private static function tone(Tone $tone): ?string
    {
        return $tone === Tone::Neutral ? null : $tone->value;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
