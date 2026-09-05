<?php
declare(strict_types=1);

namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\Services\AuthService;
use Engine\Atomic\Hook\Hook;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

class AuthServiceDeferralTest extends TestCase
{
    protected function tearDown(): void
    {
        Hook::instance()->remove_action('SESSION_STARTED');
        Auth::reset();
    }

    public function test_register_session_hooks_defers_auth_service_construction(): void
    {
        $auth = Auth::instance();
        $auth->register_session_hooks();

        $this->assertNull(
            ReflectionHelper::get($auth, 'service'),
            'AuthService must not be constructed while only registering the session hook.'
        );

        Hook::instance()->do_action('SESSION_STARTED');

        $this->assertInstanceOf(
            AuthService::class,
            ReflectionHelper::get($auth, 'service'),
            'AuthService must be constructed when the SESSION_STARTED hook fires.'
        );
    }

    public function test_register_session_hooks_registers_the_session_started_listener(): void
    {
        $auth = Auth::instance();
        $auth->register_session_hooks();

        $this->assertTrue(
            Hook::instance()->has_action('SESSION_STARTED'),
            'Deferring service construction must not skip the listener registration.'
        );
    }
}
