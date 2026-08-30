<?php
declare(strict_types=1);

namespace Tests\Support;

final class QuotaSubject
{
    public function __construct(public int $id, public string $plan) {}
}
