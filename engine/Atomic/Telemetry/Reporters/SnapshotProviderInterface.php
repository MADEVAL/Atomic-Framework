<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

interface SnapshotProviderInterface
{
    /** @return array<string, mixed> the /summary slice for this subsystem */
    public function snapshot(): array;
}
