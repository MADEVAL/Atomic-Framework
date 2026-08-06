<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Hash;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    public function test_password_hash_verifies_with_native_password_api(): void
    {
        $hash = Hash::password('secret');

        $this->assertTrue(Hash::verify_password('secret', $hash));
        $this->assertFalse(Hash::verify_password('wrong', $hash));
        $this->assertTrue(password_verify('secret', $hash));
    }

    public function test_password_needs_rehash_matches_native_password_api(): void
    {
        $hash = Hash::password('secret');

        $this->assertSame(
            password_needs_rehash($hash, PASSWORD_DEFAULT),
            Hash::password_needs_rehash($hash)
        );
    }
}
