<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Dump
{
    public function __construct(
        public readonly string $id,
        public readonly mixed $data,
    ) {}
}
