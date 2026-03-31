<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\ID;
use PHPUnit\Framework\TestCase;

class IDTest extends TestCase
{
    // ────────────────────────────────────────
    //  UUID v4 generation
    // ────────────────────────────────────────

    public function test_uuid_v4_format(): void
    {
        $uuid = ID::uuid_v4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function test_uuid_v4_uniqueness(): void
    {
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = ID::uuid_v4();
        }
        $this->assertCount(100, array_unique($uuids));
    }

    public function test_uuid_v4_version_bit(): void
    {
        $uuid = ID::uuid_v4();
        $parts = explode('-', $uuid);
        // Version nibble must be 4
        $this->assertStringStartsWith('4', $parts[2]);
    }

    public function test_uuid_v4_variant_bits(): void
    {
        $uuid = ID::uuid_v4();
        $parts = explode('-', $uuid);
        // Variant bits: first nibble of part 4 must be 8, 9, a, or b
        $firstChar = $parts[3][0];
        $this->assertContains($firstChar, ['8', '9', 'a', 'b']);
    }

    // ────────────────────────────────────────
    //  UUID v4 validation
    // ────────────────────────────────────────

    public function test_is_valid_uuid_v4(): void
    {
        $this->assertTrue(ID::is_valid_uuid_v4('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue(ID::is_valid_uuid_v4(ID::uuid_v4()));
    }

    public function test_is_valid_uuid_v4_rejects_invalid(): void
    {
        $this->assertFalse(ID::is_valid_uuid_v4(''));
        $this->assertFalse(ID::is_valid_uuid_v4('not-a-uuid'));
        $this->assertFalse(ID::is_valid_uuid_v4('550e8400-e29b-41d4-a716'));
        $this->assertFalse(ID::is_valid_uuid_v4('550e8400-e29b-41d4-a716-44665544000g'));
    }

    // ────────────────────────────────────────
    //  UUID ↔ bytes conversion
    // ────────────────────────────────────────

    public function test_uuid_to_bytes_roundtrip(): void
    {
        $uuid = ID::uuid_v4();
        $bytes = ID::uuid_v4_to_bytes($uuid);
        $this->assertNotNull($bytes);
        $this->assertSame(16, strlen($bytes));
        $back = ID::bytes_to_uuid_v4($bytes);
        $this->assertSame($uuid, $back);
    }

    public function test_uuid_to_bytes_invalid(): void
    {
        $this->assertNull(ID::uuid_v4_to_bytes('invalid'));
    }

    public function test_bytes_to_uuid_wrong_length(): void
    {
        $this->assertNull(ID::bytes_to_uuid_v4('short'));
    }

    // ────────────────────────────────────────
    //  UUID ↔ bytes (base64/JSON)
    // ────────────────────────────────────────

    public function test_uuid_to_bytes_json_roundtrip(): void
    {
        $uuid = ID::uuid_v4();
        $json = ID::uuid_v4_to_bytes_json($uuid);
        $this->assertNotNull($json);
        $back = ID::bytes_to_uuid_v4_json($json);
        $this->assertSame($uuid, $back);
    }

    // ────────────────────────────────────────
    //  Unique ID generation
    // ────────────────────────────────────────

    public function test_generate_unique_id_length(): void
    {
        $id = ID::generate_unique_id(16);
        $this->assertSame(32, strlen($id)); // hex = 2x byte count
    }

    public function test_generate_unique_id_hex(): void
    {
        $id = ID::generate_unique_id();
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $id);
    }

    public function test_generate_unique_id_json(): void
    {
        $id = ID::generate_unique_id_json(16);
        $decoded = base64_decode($id, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(16, strlen($decoded));
    }
}
