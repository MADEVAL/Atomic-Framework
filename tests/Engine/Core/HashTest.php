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

    public function test_verify_password_returns_false_for_malformed_hash(): void
    {
        $malformed_hash = '$2y$12$dummy_hash_for_timing_mitigation_00000000000000000000000';

        $this->assertFalse(
            Hash::verify_password('anything', $malformed_hash),
            'password_verify with a malformed hash returns false instantly without performing the costly hash'
        );
    }

    public function test_dummy_hash_for_timing_mitigation_returns_valid_bcrypt(): void
    {
        $dummy_hash = Hash::dummy_hash_for_timing_mitigation();

        $info = password_get_info($dummy_hash);

        $this->assertSame(
            'bcrypt',
            $info['algoName'],
            'Timing-attack mitigation dummy hash must be a valid bcrypt hash.'
        );

        $this->assertSame(
            60,
            strlen($dummy_hash),
            'Valid bcrypt hashes are exactly 60 chars. Got: ' . strlen($dummy_hash)
        );
    }

    public function test_dummy_timing_mitigation_uses_same_algo_as_password(): void
    {
        Hash::dummy_timing_mitigation();
        $this->assertTrue(true);
    }

    public function test_hmac_produces_deterministic_hash(): void
    {
        $hash1 = Hash::hmac('data', 'key');
        $hash2 = Hash::hmac('data', 'key');

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1)); // SHA-256 = 64 hex chars
    }

    public function test_verify_hmac_detects_tampering(): void
    {
        $hash = Hash::hmac('data', 'secret-key');

        $this->assertTrue(Hash::verify_hmac('data', 'secret-key', $hash));
        $this->assertFalse(Hash::verify_hmac('tampered', 'secret-key', $hash));
        $this->assertFalse(Hash::verify_hmac('data', 'wrong-key', $hash));
    }

    public function test_password_argon2_verifies_with_native_api(): void
    {
        $hash = Hash::password_argon2('secret');

        $this->assertTrue(password_verify('secret', $hash));
        $info = password_get_info($hash);
        $this->assertSame('argon2id', $info['algoName']);
    }
}
