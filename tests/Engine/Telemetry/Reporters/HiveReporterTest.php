<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

use Engine\Atomic\Telemetry\Data\HiveNode;
use Engine\Atomic\Telemetry\Reporters\HiveReporter;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

final class HiveReporterTest extends TestCase
{
    public function test_tree_lists_parents_before_children_like_the_browser_did(): void
    {
        $nodes = HiveReporter::tree([
            'APP' => ['name' => 'x', '10' => 1, '2' => 2.0, 'on' => true, 'none' => null],
            'LIST' => ['a', 1.5],
            'EMPTY' => [],
        ]);
        $lines = array_map(static fn (HiveNode $node): string => str_repeat(' ', $node->depth) . $node->key . ($node->is_branch() ? ' ' . $node->size : ' = ' . $node->shown_value()), $nodes);

        // An object's integer-like keys come first, ascending; an empty array is a list.
        self::assertSame(['APP {5}', ' 2 = 2', ' 10 = 1', ' name = "x"', ' on = true', ' none = null', 'LIST [2]', ' 0 = "a"', ' 1 = 1.5', 'EMPTY [0]'], $lines);
        self::assertSame('APP.name', $nodes[3]->path);
    }

    public function test_tree_nodes_carry_what_the_search_and_folding_need(): void
    {
        [$branch, $leaf] = HiveReporter::tree(['DB' => ['Host' => 'Local']]);
        $ui = new Ui();

        self::assertStringContainsString('data-branch data-node="DB" data-depth="0"', str_replace(' style="--depth:0"', '', (string)$ui->tree_node($branch)));
        self::assertStringContainsString('data-key="Host:" data-full="DB.Host:" data-text="db.host local"', (string)$ui->tree_node($leaf));
    }
}
