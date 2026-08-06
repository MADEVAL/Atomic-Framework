<?php
declare(strict_types=1);

namespace Tests\Engine\Cache;

use Engine\Atomic\Cache\Helpers\Payload;
use PHPUnit\Framework\TestCase;

final class PayloadWakeupProbe
{
    public static bool $woke = false;

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}

final class PayloadTest extends TestCase
{
    public function test_pack_round_trips_falsey_and_complex_values_strictly(): void
    {
        $values = [
            'false' => false,
            'null' => null,
            'zero' => 0,
            'empty-string' => '',
            'empty-array' => [],
            'nested' => ['a' => 1, 'b' => ['ok' => true]],
        ];

        foreach ($values as $label => $value) {
            $payload = Payload::unpack(Payload::pack($value, 60));

            $this->assertIsArray($payload, "payload should unpack for {$label}");
            $this->assertArrayHasKey('value', $payload);
            $this->assertEquals($value, $payload['value']);
            $this->assertIsFloat($payload['time']);
            $this->assertSame(60, $payload['ttl']);
        }
    }

    public function test_unpack_rejects_empty_non_string_legacy_and_malformed_payloads(): void
    {
        $invalid = [
            '',
            false,
            null,
            [],
            serialize(['value' => 'missing marker']),
            serialize(['__atomic_cache_payload_v1' => false, 'value' => 'bad marker']),
            serialize(['__atomic_cache_payload_v1' => true]),
            'not serialized payload',
        ];

        foreach ($invalid as $raw) {
            $this->assertFalse(Payload::unpack($raw));
        }
    }

    public function test_unpack_rejects_tampered_payload_before_deserializing_value(): void
    {
        PayloadWakeupProbe::$woke = false;

        $data = 'O:37:"Tests\Engine\Cache\PayloadWakeupProbe":0:{}';
        $raw = json_encode([
            '__atomic_cache_payload_v2' => true,
            'data' => $data,
            'hmac' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR);

        $this->assertFalse(Payload::unpack($raw));
        $this->assertFalse(PayloadWakeupProbe::$woke);
    }

    public function test_pack_rejects_objects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache values must be JSON-compatible.');

        Payload::pack((object) ['name' => 'atomic'], 60);
    }

    public function test_unpack_rejects_modified_signed_payloads(): void
    {
        $raw = Payload::pack('value', 60);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $payload['data'][0] = $payload['data'][0] === 'A' ? 'B' : 'A';

        $this->assertFalse(Payload::unpack(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    public function test_negative_ttl_is_packed_as_non_expiring_payload(): void
    {
        $payload = Payload::unpack(Payload::pack('value', -30));

        $this->assertIsArray($payload);
        $this->assertSame(0, $payload['ttl']);
        $this->assertFalse(Payload::is_expired($payload));
        $this->assertSame(0, Payload::meta($payload)[1]);
    }

    public function test_expired_boundary_is_strict(): void
    {
        $payload = Payload::unpack(Payload::pack('value', 1));
        $this->assertIsArray($payload);
        $payload['time'] = microtime(true) - 1.01;

        $this->assertTrue(Payload::is_expired($payload));
        $this->assertSame(0, Payload::meta($payload)[1]);
    }
}
