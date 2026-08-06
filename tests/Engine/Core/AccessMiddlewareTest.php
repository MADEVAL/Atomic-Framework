<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Middleware\AccessMiddleware;
use PHPUnit\Framework\TestCase;

final class AccessMiddlewareTest extends TestCase
{
    private \Base $atomic;

    protected function setUp(): void
    {
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $this->atomic = \Base::instance();
        App::instance($this->atomic);
        $this->atomic->clear('POST');
        $this->atomic->clear('HEADERS');
        
        $get_hive = \Closure::bind(fn() => $this->hive, $this->atomic, \Base::class);
        $set_hive = \Closure::bind(fn(array $h) => ($this->hive = $h), $this->atomic, \Base::class);
        $hive = $get_hive();
        unset($hive['SESSION']);
        $set_hive($hive);
        $this->atomic->set('HEADERS.Accept', 'text/html');
        $this->atomic->set('HEADERS.Content-Type', '');
        $this->atomic->set('HEADERS.X-Requested-With', '');
        $this->atomic->set('VERB', 'GET');
        $this->atomic->set('PATH', '/telemetry/logs');
        $this->atomic->set('QUERY', '');
        $this->atomic->set('ACCESS.guards.telemetry.users.viewer', [
            'id' => '11111111-1111-4111-8111-111111111111',
            'username' => 'viewer',
            'secret_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['telemetry.viewer'],
        ]);
        $this->atomic->set('app.session.driver', ''); // override db config
    }

    public function test_unauthenticated_browser_request_gets_login_form(): void
    {
        ob_start();
        $result = (new AccessMiddleware('telemetry'))->handle($this->atomic);
        $html = (string)ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('<form method="post">', $html);
        $this->assertStringContainsString('name="username"', $html);
    }

    public function test_unauthenticated_json_request_gets_401(): void
    {
        $this->atomic->set('HEADERS.Accept', 'application/json');

        ob_start();
        $result = (new AccessMiddleware('telemetry'))->handle($this->atomic);
        $json = (string)ob_get_clean();

        $this->assertFalse($result);
        $this->assertSame(['error' => 'Unauthorized'], json_decode($json, true));
    }

    public function test_invalid_login_returns_form_error(): void
    {
        $this->atomic->set('VERB', 'POST');
        $this->atomic->set('POST.username', 'viewer');
        $this->atomic->set('POST.key', 'wrong');

        ob_start();
        $result = (new AccessMiddleware('telemetry'))->handle($this->atomic);
        $html = (string)ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString('Invalid username or key.', $html);
    }

    public function test_already_authenticated_user_passes(): void
    {
        $provider = new \Engine\Atomic\Auth\ConfigUserProvider('telemetry');
        $user = $provider->find_by_credentials(['username' => 'viewer']);
        $this->atomic->set('SESSION.user_uuid', $user->get_auth_id()); // fast lane bypass
        
        $this->atomic->set('VERB', 'GET');
        
        $result = (new AccessMiddleware('telemetry'))->handle($this->atomic);
        
        $this->assertTrue($result);
    }

    public function test_safe_redirect_generates_correct_urls(): void
    {
        $middleware = new AccessMiddleware('telemetry');
        $ref = new \ReflectionMethod($middleware, 'safe_redirect');
        
        $this->atomic->set('POST.redirect', '/telemetry/dashboard');
        $this->assertSame('/telemetry/dashboard', $ref->invoke($middleware, $this->atomic));
        
        $this->atomic->set('POST.redirect', '//evil.com/');
        $this->atomic->set('PATH', '/telemetry/hive');
        $this->atomic->set('QUERY', 'filter=1');
        $this->assertSame('/telemetry/hive?filter=1', $ref->invoke($middleware, $this->atomic));
        
        $this->atomic->set('POST.redirect', 'https://evil.com');
        $this->assertSame('/telemetry/hive?filter=1', $ref->invoke($middleware, $this->atomic));
        
        $this->atomic->set('POST.redirect', '');
        $this->assertSame('/telemetry/hive?filter=1', $ref->invoke($middleware, $this->atomic));
    }
}
