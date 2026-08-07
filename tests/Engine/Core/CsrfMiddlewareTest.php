<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private \Base $atomic;

    protected function setUp(): void
    {
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $this->atomic = \Base::instance();
        App::instance($this->atomic);
        $this->atomic->clear('POST');
        $this->atomic->clear('HEADERS');
        $this->atomic->clear('BODY');
        $this->atomic->clear('SESSION');
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('PATH', '/test');
    }

    public function test_safe_methods_pass_through_handle(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->atomic->set('VERB', $method);

            $result = (new CsrfMiddleware())->handle($this->atomic);

            $this->assertTrue($result, "$method request should pass without CSRF check");
        }
    }

    public function test_unsafe_method_without_stored_token_is_rejected(): void
    {
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('SESSION.csrf_token', '');

        ob_start();
        $result = (new CsrfMiddleware())->handle($this->atomic);
        ob_end_clean();

        $this->assertFalse(
            $result,
            'POST without stored token must be rejected (fail-closed)'
        );
    }

    public function test_unsafe_method_with_valid_token_passes(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('SESSION.csrf_token', $token);
        $this->atomic->set('HEADERS.X-CSRF-Token', $token);

        $result = (new CsrfMiddleware())->handle($this->atomic);

        $this->assertTrue($result, 'POST with matching token should pass');
    }

    public function test_unsafe_method_with_wrong_token_is_rejected(): void
    {
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('SESSION.csrf_token', 'valid-token');
        $this->atomic->set('HEADERS.X-CSRF-Token', 'wrong-token');

        ob_start();
        $result = (new CsrfMiddleware())->handle($this->atomic);
        ob_end_clean();

        $this->assertFalse($result, 'POST with mismatched token must be rejected');
    }

    public function test_unsafe_method_without_stored_token_is_rejected_via_process(): void
    {
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('SESSION.csrf_token', '');

        $response = (new CsrfMiddleware())->process(null, fn($req) => null);

        $this->assertSame(403, $response->status(),
            'process() must return 403 when no token stored');
    }

    public function test_safe_methods_pass_through_process(): void
    {
        $this->atomic->set('VERB', 'GET');

        $response = (new CsrfMiddleware())->process(new \stdClass(), fn($req) => \Engine\Atomic\Http\Response::html('ok', 200));

        $this->assertSame(200, $response->status(),
            'GET request should pass through process() without CSRF check');
    }
}
