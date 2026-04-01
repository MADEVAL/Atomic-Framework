<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Guard;
use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase
{
    public function test_is_guest_when_no_user(): void
    {
        $this->assertTrue(Guard::is_guest());
    }

    public function test_is_authenticated_when_no_user(): void
    {
        $this->assertFalse(Guard::is_authenticated());
    }

    public function test_has_role_returns_false_when_no_user(): void
    {
        $this->assertFalse(Guard::has_role('admin'));
    }

    public function test_has_any_role_returns_false_when_no_user(): void
    {
        $this->assertFalse(Guard::has_any_role(['admin', 'moderator']));
    }

    public function test_lacks_role_returns_true_when_no_user(): void
    {
        $this->assertTrue(Guard::lacks_role('admin'));
    }

    public function test_lacks_any_role_returns_true_when_no_user(): void
    {
        $this->assertTrue(Guard::lacks_any_role(['admin']));
    }

    public function test_role_to_slug_string(): void
    {
        $ref = new \ReflectionMethod(Guard::class, 'role_to_slug');
        $this->assertSame('admin', $ref->invoke(null, 'admin'));
    }

    public function test_role_to_slug_backed_enum(): void
    {
        $ref = new \ReflectionMethod(Guard::class, 'role_to_slug');
        $role = \Engine\Atomic\Enums\Role::ADMIN;
        $this->assertSame('admin', $ref->invoke(null, $role));
    }
}
