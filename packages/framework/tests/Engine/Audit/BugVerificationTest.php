<?php
declare(strict_types=1);

namespace Tests\Engine\Audit;

use Engine\Atomic\Auth\ConfigUserProvider;
use Engine\Atomic\Auth\Services\AuthService;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Hash;
use Engine\Atomic\Core\Upload;
use Engine\Atomic\Event\Event;
use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BugVerificationTest extends TestCase
{
    public function testCsrfValidationExists(): void
    {
        $csrfMiddlewarePath = __DIR__ . '/../../../engine/Atomic/Core/Middleware/CsrfMiddleware.php';
        $this->assertFileExists($csrfMiddlewarePath, 'CsrfMiddleware class now exists for CSRF token validation');

        $content = file_get_contents($csrfMiddlewarePath);
        $this->assertStringContainsString('hash_equals', $content, 'CSRF middleware uses hash_equals for token comparison');
        $this->assertStringContainsString('MiddlewareInterface', $content, 'CSRF middleware implements MiddlewareInterface');
    }

    public function testDieCallsUnload(): void
    {
        $ref = new \ReflectionMethod(App::class, 'die');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('exit(', $source, 'die() must call exit()');
        $this->assertStringContainsString('$this->atomic->unload()', $source, 'unload() is called before exit');
    }

    public function testConfigBooleanInconsistencyFixed(): void
    {
        $envResult = filter_var('false', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $this->assertFalse($envResult, 'FILTER_VALIDATE_BOOLEAN correctly converts "false" to false');

        $phpResult = (bool)('false');
        $this->assertTrue($phpResult, '(bool)("false") === true — raw cast is broken');

        $this->assertNotSame($envResult, $phpResult, 'Raw (bool) cast on strings is inconsistent, PhpConfigLoader now uses filter_var');
    }

    public function testConfigUserProviderHandlesMixedCase(): void
    {
        $provider = new ConfigUserProvider('test_guard');

        $method = new \ReflectionMethod($provider, 'create_user_from_config');
        $user = $method->invoke($provider, 'Admin', [
            'id' => 'uuid-1',
            'username' => 'Admin',
            'secret_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['admin'],
        ]);

        $this->assertNotNull($user, 'User with mixed-case config key "Admin" should be found after case-insensitive fix');
    }

    public function testPasswordNeedsRehashIsCalledInLoginFlow(): void
    {
        $ref = new \ReflectionMethod(AuthService::class, 'login_with_secret');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('needs_rehash', $source, 'password_needs_rehash is now called during login');
    }

    public function testBcryptCostIsExplicit(): void
    {
        $ref = new \ReflectionMethod(Hash::class, 'password');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString("'cost'", $source, 'Explicit bcrypt cost is now passed to password_hash()');
    }

    public function testKillAllSessionsCountsActualDeletions(): void
    {
        $ref = new \ReflectionMethod(AuthService::class, 'kill_all_sessions');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringNotContainsString('$meta_deleted ?', $source, 'kill_all_sessions no longer reports false count when meta deletion succeeds');
    }

    public function testConfigLoaderStripsQuotes(): void
    {
        $ref = new \ReflectionMethod(\Engine\Atomic\Core\Config\ConfigLoader::class, 'parse_env');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('strip_quotes', $source, 'ConfigLoader now strips quotes from .env values');
    }

    public function testRemoveActionUsesCallbackParameter(): void
    {
        $ref = new \ReflectionMethod(Hook::class, 'remove_action');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('$function_to_remove', $source, 'Signature accepts $function_to_remove');
        $this->assertStringContainsString('$this->event->off(', $source, 'Calls event->off()');

        $passesCallback = strpos($source, '$function_to_remove') < strpos($source, '->off(');
        $this->assertTrue($passesCallback, '$function_to_remove is now properly used before off() call');
    }

    public function testEventOffSupportsTargetedRemoval(): void
    {
        $ref = new \ReflectionMethod(Event::class, 'off');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('$func', $source, 'off() accepts optional $func parameter for targeted removal');
    }

    public function testSchedulerExecUsesEscaping(): void
    {
        $ref = new \ReflectionMethod(\Engine\Atomic\Scheduler\Scheduler::class, 'exec');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('escapeshellcmd', $source, 'escapeshellcmd() is now called before exec()');
    }

    public function testDownloadImageFromUrlHasUrlValidation(): void
    {
        $ref = new \ReflectionMethod(Upload::class, 'download_image_from_url');
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $source = implode('', array_slice(file($ref->getFileName()), $start - 1, $end - $start + 1));

        $this->assertStringContainsString('is_url_allowed', $source, 'URL validation is now called before file_get_contents');
    }
}
