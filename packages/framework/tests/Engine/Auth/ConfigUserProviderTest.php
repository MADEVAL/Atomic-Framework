<?php
declare(strict_types=1);

namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\ConfigUserProvider;
use Engine\Atomic\Core\App;
use PHPUnit\Framework\TestCase;

final class ConfigUserProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $atomic = \Base::instance();
        App::instance($atomic);
        $atomic->set('ACCESS.guards.telemetry.users.viewer', [
            'id' => '11111111-1111-4111-8111-111111111111',
            'username' => 'viewer',
            'secret_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['telemetry.viewer'],
        ]);
    }

    public function test_finds_user_by_credentials_and_id(): void
    {
        $provider = new ConfigUserProvider('telemetry');

        $user = $provider->find_by_credentials(['username' => 'viewer']);
        $this->assertNotNull($user);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $user->get_auth_id());
        $this->assertSame(['telemetry.viewer'], $user->get_role_slugs());
        $this->assertTrue(password_verify('secret', (string)$user->get_password_hash()));

        $this->assertSame($user->get_auth_id(), $provider->find_by_id($user->get_auth_id())?->get_auth_id());
        $this->assertNull($provider->find_by_credentials(['username' => 'missing']));
    }

    public function test_requires_explicit_user_schema(): void
    {
        $atomic = \Base::instance();
        $atomic->set('ACCESS.guards.telemetry.users.no_id', [
            'username' => 'no_id',
            'secret_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['telemetry.viewer'],
        ]);
        $atomic->set('ACCESS.guards.telemetry.users.alias_hash', [
            'id' => '33333333-3333-4333-8333-333333333333',
            'username' => 'alias_hash',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['telemetry.viewer'],
        ]);
        $atomic->set('ACCESS.guards.telemetry.users.mismatch', [
            'id' => '44444444-4444-4444-8444-444444444444',
            'username' => 'other',
            'secret_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'roles' => ['telemetry.viewer'],
        ]);

        $provider = new ConfigUserProvider('telemetry');

        $this->assertNull($provider->find_by_credentials(['username' => 'no_id']));
        $this->assertNull($provider->find_by_credentials(['username' => 'alias_hash']));
        $this->assertNull($provider->find_by_credentials(['username' => 'mismatch']));
        $this->assertNull($provider->find_by_id('33333333-3333-4333-8333-333333333333'));
        $this->assertNull($provider->find_by_id('44444444-4444-4444-8444-444444444444'));
    }

    public function test_rejects_credential_aliases(): void
    {
        $provider = new ConfigUserProvider('telemetry');

        $this->assertNull($provider->find_by_credentials(['login' => 'viewer']));
    }

    public function test_finds_user_by_mixed_case_username(): void
    {
        $atomic = \Base::instance();
        $atomic->set('ACCESS.guards.telemetry.users.AdminUser', [
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'username' => 'AdminUser',
            'secret_hash' => password_hash('adminsecret', PASSWORD_DEFAULT),
            'roles' => ['admin'],
        ]);

        $provider = new ConfigUserProvider('telemetry');

        $user = $provider->find_by_credentials(['username' => 'adminuser']);
        $this->assertNotNull($user, 'Should find user with lowercase lookup when config has mixed case');
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $user->get_auth_id());

        $user2 = $provider->find_by_credentials(['username' => 'AdminUser']);
        $this->assertNotNull($user2, 'Should find user with exact case lookup');
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $user2->get_auth_id());
    }
}
