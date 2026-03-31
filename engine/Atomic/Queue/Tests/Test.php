<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Tests;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Queue\Managers\TelemetryManager;

class Test {
    public function success(array $params, $smth) {
    }
    public function failure(array $params, $smth) {
        throw new \Exception("Job failed as expected");
    }
    public function timeout(array $params, $smth) {
        sleep(60);
    }
    public function event(array $params, $smth) {
        $telemetry_manager = new TelemetryManager();
        $telemetry_manager->push_telemetry("Custom event from job");
    }
}