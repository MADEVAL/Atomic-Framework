<?php
declare(strict_types=1);

namespace Tests\Engine\CLI\Console;

use Engine\Atomic\CLI\Console\CommandSuggester;
use PHPUnit\Framework\TestCase;

class CommandSuggesterTest extends TestCase
{
    public function test_incomplete_segment_returns_multiple_ranked_suggestions(): void
    {
        $suggester = new CommandSuggester();

        $suggestions = $suggester->suggest('queue/wo', [
            'schedule/work',
            'queue/monitor',
            'queue/worker',
            'cache/clear',
        ]);

        $this->assertSame(['queue/worker', 'schedule/work'], $suggestions);
    }

    public function test_strong_same_group_match_hides_cross_group_fuzzy_match(): void
    {
        $suggester = new CommandSuggester();

        $suggestions = $suggester->suggest('queue/worke', [
            'queue/worker',
            'schedule/work',
        ]);

        $this->assertSame(['queue/worker'], $suggestions);
    }

    public function test_nested_command_namespace_is_used_for_group_filtering(): void
    {
        $suggester = new CommandSuggester();

        $suggestions = $suggester->suggest('access/user/creatx', [
            'access/user/create',
            'access/admin/create',
        ]);

        $this->assertSame(['access/user/create'], $suggestions);
    }

    public function test_typo_prefers_same_command_group(): void
    {
        $suggester = new CommandSuggester();

        $suggestions = $suggester->suggest('queue/workr', [
            'schedule/work',
            'queue/monitor',
            'queue/worker',
        ]);

        $this->assertSame('queue/worker', $suggestions[0]);
        $this->assertNotContains('queue/monitor', $suggestions);
    }

    public function test_unrelated_input_has_no_suggestions(): void
    {
        $suggester = new CommandSuggester();

        $this->assertSame([], $suggester->suggest('banana', [
            'queue/worker',
            'schedule/work',
            'cache/clear',
        ]));
    }

    public function test_suggestions_are_unique_and_limited(): void
    {
        $suggester = new CommandSuggester(2);

        $suggestions = $suggester->suggest('do/wo', [
            '/do/work',
            'do/worker',
            'do/work',
            'schedule/work',
        ]);

        $this->assertSame(['do/work', 'do/worker'], $suggestions);
    }
}
