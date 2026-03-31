<?php
declare(strict_types=1);

namespace Tests\Engine\Enums;

use Engine\Atomic\Enums\Rule;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase
{
    public function test_all_rules_have_string_values(): void
    {
        foreach (Rule::cases() as $rule) {
            $this->assertIsString($rule->value);
            $this->assertNotEmpty($rule->value);
        }
    }

    public function test_expected_rules_exist(): void
    {
        $expected = [
            'UUID_V4', 'EMAIL', 'URL', 'ENUM', 'REGEX', 'CALLBACK',
            'NUM_MIN', 'NUM_MAX', 'STR_MIN', 'STR_MAX',
            'MB_MIN', 'MB_MAX', 'PASSWORD_ENTROPY',
        ];
        $actual = array_map(fn(Rule $r) => $r->name, Rule::cases());
        foreach ($expected as $name) {
            $this->assertContains($name, $actual, "Rule::{$name} is missing");
        }
    }

    public function test_rule_values_are_snake_case(): void
    {
        foreach (Rule::cases() as $rule) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $rule->value,
                "Rule::{$rule->name} value '{$rule->value}' is not snake_case"
            );
        }
    }

    public function test_password_entropy_rule_value(): void
    {
        $this->assertSame('password_entropy', Rule::PASSWORD_ENTROPY->value);
    }
}
