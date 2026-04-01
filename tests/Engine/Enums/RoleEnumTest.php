<?php
declare(strict_types=1);

namespace Tests\Engine\Enums;

use Engine\Atomic\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleEnumTest extends TestCase
{
    public function test_expected_roles(): void
    {
        $expected = ['admin', 'seller', 'buyer', 'moderator', 'support'];
        $actual = Role::all();
        foreach ($expected as $role) {
            $this->assertContains($role, $actual, "Role '{$role}' missing");
        }
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Role::is_valid('admin'));
        $this->assertTrue(Role::is_valid('buyer'));
        $this->assertFalse(Role::is_valid('superadmin'));
        $this->assertFalse(Role::is_valid(''));
    }

    public function test_all_returns_values(): void
    {
        $all = Role::all();
        $this->assertCount(count(Role::cases()), $all);
        foreach ($all as $v) {
            $this->assertIsString($v);
        }
    }

    public function test_from_string(): void
    {
        $this->assertSame(Role::ADMIN, Role::from('admin'));
        $this->assertSame(Role::BUYER, Role::from('buyer'));
    }

    public function test_tryFrom_invalid(): void
    {
        $this->assertNull(Role::tryFrom('invalid'));
    }
}
