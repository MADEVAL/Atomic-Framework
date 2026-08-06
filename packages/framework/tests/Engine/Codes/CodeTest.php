<?php
declare(strict_types=1);

namespace Tests\Engine\Codes;

use Engine\Atomic\Codes\Code;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    public function test_generic_success(): void
    {
        $this->assertSame('200', Code::SUCCESS);
    }

    public function test_generic_bad_request(): void
    {
        $this->assertSame('400', Code::BAD_REQUEST);
    }

    public function test_generic_unauthorized(): void
    {
        $this->assertSame('401', Code::UNAUTHORIZED);
    }

    public function test_generic_forbidden(): void
    {
        $this->assertSame('403', Code::FORBIDDEN);
    }

    public function test_generic_too_many_requests(): void
    {
        $this->assertSame('429', Code::TOO_MANY_REQUESTS);
    }

    public function test_generic_nonce_invalid(): void
    {
        $this->assertSame('440', Code::NONCE_INVALID);
    }

    public function test_generic_server_error(): void
    {
        $this->assertSame('500', Code::SERVER_ERROR);
    }

    public function test_generic_service_unavailable(): void
    {
        $this->assertSame('503', Code::SERVICE_UNAVAILABLE);
    }

    public function test_oauth_token_error(): void
    {
        $this->assertSame('450', Code::OAUTH_TOKEN_ERROR);
    }

    public function test_oauth_user_data_error(): void
    {
        $this->assertSame('451', Code::OAUTH_USER_DATA_ERROR);
    }

    public function test_oauth_account_already_linked(): void
    {
        $this->assertSame('452', Code::OAUTH_ACCOUNT_ALREADY_LINKED);
    }

    public function test_oauth_not_configured(): void
    {
        $this->assertSame('453', Code::OAUTH_NOT_CONFIGURED);
    }

    public function test_oauth_invalid_state(): void
    {
        $this->assertSame('454', Code::OAUTH_INVALID_STATE);
    }

    public function test_all_codes_are_string(): void
    {
        $ref = new \ReflectionClass(Code::class);
        foreach ($ref->getConstants() as $name => $value) {
            $this->assertIsString($value, "Constant {$name} should be a string");
        }
    }

    public function test_all_codes_numeric(): void
    {
        $ref = new \ReflectionClass(Code::class);
        foreach ($ref->getConstants() as $name => $value) {
            $this->assertTrue(is_numeric($value), "Constant {$name} should be numeric");
        }
    }
}
