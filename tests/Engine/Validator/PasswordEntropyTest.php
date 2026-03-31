<?php
declare(strict_types=1);

namespace Tests\Engine\Validator;

use Engine\Atomic\Validator\Validator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PasswordEntropyTest extends TestCase
{
    protected function setUp(): void
    {
        // Prime F3 Audit singleton to prevent "risky" error handler warning
        \Audit::instance();
    }

    // ────────────────────────────────────────
    //  password_entropy() with default threshold (18.0)
    // ────────────────────────────────────────

    #[DataProvider('entropyDefaultProvider')]
    public function test_password_entropy_default_threshold(mixed $password, bool $expected): void
    {
        $this->assertSame($expected, Validator::password_entropy($password));
    }

    public static function entropyDefaultProvider(): array
    {
        return [
            // Weak (below 18.0 entropy or < 8 chars)
            'sex (entropy ~8)'           => ['sex', false],          // too short + low entropy
            'secret (entropy ~14)'       => ['secret', false],       // too short (6 chars)
            'short7ch'                   => ['short7c', false],      // 7 chars — below min length

            // F3 Audit::entropy() uses Shannon entropy based on charset pool × length.
            // 8 chars of any single charset (digits, lowercase, etc.) = 18.0 exactly,
            // which is the boundary threshold. These PASS because >= 18.0.
            '12345678 (boundary 18.0)'   => ['12345678', true],
            'aaaaaaaa (boundary 18.0)'   => ['aaaaaaaa', true],
            'abcabcab (boundary 18.0)'   => ['abcabcab', true],

            // Strong (>= 18.0 entropy and >= 8 chars)
            'password (entropy ~18)'     => ['password', true],
            'p4ss_w0rd (entropy ~19.5)'  => ['p4ss_w0rd', true],
            'dK2#!b846 (entropy ~25.5)'  => ['dK2#!b846', true],
            'MyStr0ng!Pass'              => ['MyStr0ng!Pass', true],
            'c0mpl3x#P@ss!'             => ['c0mpl3x#P@ss!', true],

            // Edge cases
            'empty string'               => ['', false],
            'null'                       => [null, false],
            'integer'                    => [12345678, false],
            'whitespace 8 chars'         => ['        ', true],      // F3 counts as 18.0 (boundary)
        ];
    }

    // ────────────────────────────────────────
    //  password_entropy() with custom threshold
    // ────────────────────────────────────────

    public function test_password_entropy_custom_low_threshold(): void
    {
        // With threshold of 10.0, "password" should pass easily
        $this->assertTrue(Validator::password_entropy('password', 10.0));
    }

    public function test_password_entropy_custom_high_threshold(): void
    {
        // With threshold of 30.0, even decent passwords may fail
        $this->assertFalse(Validator::password_entropy('password', 30.0));
        // Boundary value that passes the default threshold (18.0) must fail at a higher one
        $this->assertFalse(Validator::password_entropy('12345678', 20.0));
    }

    public function test_password_entropy_mixed_charset_custom_threshold(): void
    {
        // Mixed-case + digit bonus pushes entropy above 20.0
        $this->assertTrue(Validator::password_entropy('Password1', 20.0));
    }

    public function test_password_entropy_very_strong(): void
    {
        // Long complex password should pass even high threshold
        $this->assertTrue(Validator::password_entropy('Xk9#mL2$vQ7!nR4@', 25.0));
    }

    // ────────────────────────────────────────
    //  Min length enforcement (8 chars)
    // ────────────────────────────────────────

    public function test_password_entropy_rejects_short_high_entropy(): void
    {
        // Even if entropy is high for 7 chars, length < 8 must fail
        $this->assertFalse(Validator::password_entropy('aB3#xQ!', 1.0));
    }

    public function test_password_entropy_accepts_8_char_boundary(): void
    {
        // 8 chars with decent entropy and low threshold
        $this->assertTrue(Validator::password_entropy('aB3#xQ!z', 10.0));
    }
}
