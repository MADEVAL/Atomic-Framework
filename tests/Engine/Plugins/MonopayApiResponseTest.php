<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Plugins\Monopay\Api;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Found live on monobank's test environment: the invoice status of a
 * declined payment carries errCode/failureReason next to status=failure.
 * That is a valid status payload, not an API error; treating it as one
 * left declined payments stuck in 'created' and their keys unreleased.
 */
final class MonopayApiResponseTest extends TestCase
{
    private function interpret(array $decoded): array
    {
        $method = new ReflectionMethod(Api::class, 'interpret_decoded');

        return $method->invoke(null, $decoded);
    }

    public function test_failed_invoice_status_with_err_code_is_a_successful_response(): void
    {
        $payload = [
            'invoiceId' => '261106FC1GzRLtNnHUUq',
            'status' => 'failure',
            'failureReason' => 'Недостатньо коштів',
            'errCode' => '59',
            'amount' => 100,
            'ccy' => 980,
            'finalAmount' => 0,
            'reference' => 'b2a1c0de-0000-4000-8000-000000000001',
        ];

        $result = $this->interpret($payload);

        $this->assertTrue($result['ok']);
        $this->assertSame($payload, $result['data']);
        $this->assertNull($result['error']);
    }

    public function test_pure_error_body_is_still_an_api_error(): void
    {
        $result = $this->interpret(['errCode' => '1001', 'errText' => 'invalid request']);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid request', $result['error']);
        $this->assertSame('1001', $result['errorCode']);
    }
}
