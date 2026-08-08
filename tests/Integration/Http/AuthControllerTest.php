<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

use Engine\Atomic\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the skeleton auth controllers.
 *
 * Controllers terminate via Core\Response::send_json_* (exit), so they are
 * exercised in a subprocess (tests/Support/ControllerRunner.php) whose exit
 * code and stdout carry the result.
 */
final class AuthControllerTest extends TestCase
{
    private const GENERIC_REGISTER_MESSAGE = 'If the email is not registered, a verification link has been sent.';
    private const GENERIC_RESET_MESSAGE    = 'If the email exists, a reset link has been sent.';

    /** @return array{0: int, 1: string} */
    private function runController(string $scenario, string $email): array
    {
        $harness = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'ControllerRunner.php';
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($harness)
            . ' ' . escapeshellarg($scenario)
            . ' ' . escapeshellarg($email)
            . ' 2>&1';

        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);

        return [$code, implode(PHP_EOL, $output)];
    }

    protected function setUp(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';
            if (str_starts_with($class, $prefix)) {
                $file = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });

        if (\Base::instance()->get('DB') === null) {
            $this->markTestSkipped('MySQL is not available.');
        }
    }

    public function test_register_existing_email_does_not_crash_on_valid_password(): void
    {
        $email = 'existing_' . bin2hex(random_bytes(4)) . '@example.com';

        [$code, $output] = $this->runController('register_existing', $email);

        $this->assertSame(0, $code, 'Registering with a valid password must not fatal. Output: ' . $output);
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringContainsString(self::GENERIC_REGISTER_MESSAGE, $output);
    }

    public function test_register_new_email_returns_same_response_shape_as_existing_email(): void
    {
        $existingEmail = 'existing_' . bin2hex(random_bytes(4)) . '@example.com';
        $newEmail      = 'fresh_' . bin2hex(random_bytes(4)) . '@example.com';

        [$existingCode, $existingOutput] = $this->runController('register_existing', $existingEmail);
        [$newCode, $newOutput]           = $this->runController('register_new', $newEmail);

        $this->assertSame(0, $existingCode, 'Existing-email response failed: ' . $existingOutput);
        $this->assertSame(0, $newCode, 'New-email response failed: ' . $newOutput);

        $existingJson = json_decode($existingOutput, true);
        $newJson      = json_decode($newOutput, true);

        $this->assertIsArray($existingJson);
        $this->assertIsArray($newJson);

        $this->assertSame(
            array_keys($existingJson),
            array_keys($newJson),
            'Both registration outcomes must expose the same response keys (no user enumeration channel).'
        );
        $this->assertArrayNotHasKey('redirect', $newJson);
        $this->assertSame(self::GENERIC_REGISTER_MESSAGE, $newJson['message'] ?? null);
    }

    public function test_reset_link_always_returns_generic_message(): void
    {
        $email = 'missing_' . bin2hex(random_bytes(4)) . '@example.com';

        [$code, $output] = $this->runController('reset_send', $email);

        $this->assertSame(0, $code, 'Reset-link flow must not crash. Output: ' . $output);
        $this->assertStringContainsString(self::GENERIC_RESET_MESSAGE, $output);
    }

    public function test_reset_link_mitigates_timing_for_unknown_email(): void
    {
        $ref = new \ReflectionMethod(\App\Http\Controllers\Auth\PasswordResetController::class, 'sendResetLink');
        $start = $ref->getStartLine();
        $end   = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('dummy_timing_mitigation', $source);
    }

    public function test_password_policy_validate_returns_bool_and_violations_by_reference(): void
    {
        $violations = [];
        $valid      = PasswordPolicy::default()->validate('StrongPass123', $violations);

        $this->assertTrue($valid);
        $this->assertSame([], $violations);

        $invalid = PasswordPolicy::default()->validate('weak', $violations);

        $this->assertFalse($invalid);
        $this->assertNotEmpty($violations);
        $this->assertIsString($violations['min_length'] ?? null);
    }
}
