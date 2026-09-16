<?php

declare(strict_types=1);
namespace Tests\Engine\Queue;

use Engine\Atomic\Queue\Payload;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function test_payload_round_trips_through_canonical_json_storage_format(): void
    {
        $payload = [
            'handler' => 'App\\Jobs\\Example@run',
            'data' => ['message' => '<script>alert(1)</script>'],
            'uuid_batch' => 'batch-1',
        ];

        $this->assertSame($payload, Payload::decode(Payload::encode($payload)));
    }

    public function test_payload_rejects_html_entity_encoded_json(): void
    {
        $this->expectException(\JsonException::class);

        Payload::decode('{&quot;handler&quot;:&quot;App\\\\Jobs\\\\Example@run&quot;}');
    }
}
