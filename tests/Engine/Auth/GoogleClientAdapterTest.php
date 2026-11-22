<?php
declare(strict_types=1);
namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\Adapters\GoogleClientAdapter;
use PHPUnit\Framework\TestCase;

class GoogleClientAdapterTest extends TestCase
{
    public function test_map_user_info_passes_verified_email_flag(): void
    {
        $result = GoogleClientAdapter::map_user_info($this->userinfo(true));

        $this->assertSame([
            'id'             => '42',
            'email'          => 'user@example.com',
            'name'           => 'Test User',
            'given_name'     => 'Test',
            'family_name'    => 'User',
            'picture'        => 'https://example.com/pic.jpg',
            'email_verified' => true,
        ], $result);
    }

    public function test_map_user_info_treats_missing_flag_as_unverified(): void
    {
        $this->assertFalse(GoogleClientAdapter::map_user_info($this->userinfo(null))['email_verified']);
        $this->assertFalse(GoogleClientAdapter::map_user_info($this->userinfo(false))['email_verified']);
    }

    private function userinfo(?bool $verified): object
    {
        return new class($verified) {
            public function __construct(private ?bool $verified) {}
            public function getId() { return 42; }
            public function getEmail() { return 'user@example.com'; }
            public function getName() { return 'Test User'; }
            public function getGivenName() { return 'Test'; }
            public function getFamilyName() { return 'User'; }
            public function getPicture() { return 'https://example.com/pic.jpg'; }
            public function getVerifiedEmail() { return $this->verified; }
        };
    }
}
