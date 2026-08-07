<?php

declare(strict_types=1);

namespace Engine\Atomic\Security;

final class CsrfTokenManager
{
    /** @param object $session Must have get(), set(), has() methods */
    public function __construct(
        private readonly object $session,
        private readonly string $tokenKey = '_csrf_token',
    ) {}

    public function token(): string
    {
        if ($this->session->has($this->tokenKey)) {
            return $this->session->get($this->tokenKey);
        }

        $token = $this->generate();
        $this->session->set($this->tokenKey, $token);
        return $token;
    }

    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validate(string $token): bool
    {
        $stored = $this->session->get($this->tokenKey);

        if ($stored === null || $stored === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public function field(): string
    {
        return '<input type="hidden" name="' . $this->tokenKey . '" value="' . $this->token() . '">';
    }

    public function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . $this->token() . '">';
    }

    public static function validateStatic(\Base $atomic, string $token): bool
    {
        $stored = $atomic->get('SESSION.csrf_token');

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $token);
    }
}
