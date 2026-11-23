<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;

/**
 * Outcome of a password login. A locked-out attempt is reported separately
 * from invalid credentials so callers can tell the user when to retry.
 */
final class LoginResult
{
    private function __construct(
        public readonly LoginStatus $status,
        public readonly ?AuthenticatableInterface $user = null,
        public readonly int $retry_after_seconds = 0,
    ) {}

    public static function success(AuthenticatableInterface $user): self
    {
        return new self(LoginStatus::Success, $user);
    }

    public static function invalid_credentials(): self
    {
        return new self(LoginStatus::InvalidCredentials);
    }

    public static function locked_out(int $retry_after_seconds): self
    {
        return new self(LoginStatus::LockedOut, null, max(1, $retry_after_seconds));
    }
}
