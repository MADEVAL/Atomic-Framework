<?php

declare(strict_types=1);

namespace Tests\Engine\Security;

use Engine\Atomic\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function test_min_length_default_is_8(): void
    {
        $policy = new PasswordPolicy();
        $this->assertTrue($policy->validate('abcdefgh'));
        $this->assertFalse($policy->validate('short'));
    }

    public function test_custom_min_length(): void
    {
        $policy = new PasswordPolicy(minLength: 12);
        $this->assertTrue($policy->validate('twelve_chars!'));
        $this->assertFalse($policy->validate('eleven_char'));
    }

    public function test_require_mixed_case(): void
    {
        $policy = new PasswordPolicy(requireMixedCase: true);
        $this->assertTrue($policy->validate('Abcdefgh'));
        $this->assertFalse($policy->validate('abcdefgh'));
    }

    public function test_require_numbers(): void
    {
        $policy = new PasswordPolicy(requireNumbers: true);
        $this->assertTrue($policy->validate('abcdef1h'));
        $this->assertFalse($policy->validate('abcdefgh'));
    }

    public function test_require_symbols(): void
    {
        $policy = new PasswordPolicy(requireSymbols: true);
        $this->assertTrue($policy->validate('abcdef!h'));
        $this->assertFalse($policy->validate('abcdefgh'));
    }

    public function test_all_rules_combined(): void
    {
        $policy = new PasswordPolicy(
            minLength: 12,
            requireMixedCase: true,
            requireNumbers: true,
            requireSymbols: true,
        );

        $this->assertTrue($policy->validate('Abcdef1!hijkl'));
        $this->assertFalse($policy->validate('short'));
    }

    public function test_errors_returns_violated_rules(): void
    {
        $policy = new PasswordPolicy(
            minLength: 12,
            requireMixedCase: true,
            requireNumbers: true,
        );

        $errors = $policy->validate('short', $violations);
        $this->assertFalse($errors);
        $this->assertNotEmpty($violations);
    }

    public function test_password_policy_defaults_from_config_schema(): void
    {
        $policy = PasswordPolicy::default();

        // Should at minimum validate
        $this->assertTrue($policy->validate('ReasonablyGood1!'));
    }
}
