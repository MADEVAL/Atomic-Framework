<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\LegacyLintTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Definition\FieldConfLinter;
use Engine\Atomic\Enums\Rule;
use PHPUnit\Framework\TestCase;

final class LegacyLintTest extends TestCase
{
    // 6. lint false positives on valid legacy conf
    public function test_lint_valid_legacy(): void
    {
        $this->assertSame([], FieldConfLinter::lint([
            'profile' => ['type' => 'JSON'],
            'payload' => ['type' => 'SERIALIZED'],
            'children' => ['has-many' => ['X', 'owner']],
            'owner' => ['belongs-to-one' => 'X'],
            'single' => ['has-one' => ['X', 'owner']],
            'tags' => ['belongs-to-many' => ['X', 'owners']],
        ]));
    }

    public function test_missing_rule_argument_and_unknown_type_are_reported(): void
    {
        $errors = FieldConfLinter::lint([
            'amount' => ['type' => 'DECIMAL', 'rules' => [Rule::NUM_MIN]],
            'label' => ['type' => 'DT_NOPE'],
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('rule "num_min" without a "min" argument', $errors[0]);
        $this->assertStringContainsString('unsupported type "DT_NOPE"', $errors[1]);
    }

    public function test_broken_relation_target_is_reported(): void
    {
        $this->assertSame(['Field "owner" has invalid "belongs-to-one" relation target.'],
            FieldConfLinter::lint(['owner' => ['belongs-to-one' => []]]));
    }
}
