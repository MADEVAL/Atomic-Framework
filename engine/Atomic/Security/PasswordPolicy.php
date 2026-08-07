<?php

declare(strict_types=1);

namespace Engine\Atomic\Security;
if (!defined('ATOMIC_START')) exit;

final class PasswordPolicy
{
    /** @param array<string, string> &$violations */
    public function __construct(
        private readonly int $minLength = 8,
        private readonly bool $requireMixedCase = false,
        private readonly bool $requireNumbers = false,
        private readonly bool $requireSymbols = false,
    ) {}

    public static function default(): self
    {
        return new self(minLength: 12, requireMixedCase: true, requireNumbers: true);
    }

    /** @param array<string, string> &$violations */
    public function validate(string $password, ?array &$violations = null): bool
    {
        $violations = [];

        if (mb_strlen($password) < $this->minLength) {
            $violations['min_length'] = "Password must be at least {$this->minLength} characters.";
        }

        if ($this->requireMixedCase && (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password))) {
            $violations['mixed_case'] = 'Password must contain both uppercase and lowercase letters.';
        }

        if ($this->requireNumbers && !preg_match('/[0-9]/', $password)) {
            $violations['numbers'] = 'Password must contain at least one number.';
        }

        if ($this->requireSymbols && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $violations['symbols'] = 'Password must contain at least one symbol.';
        }

        return empty($violations);
    }
}