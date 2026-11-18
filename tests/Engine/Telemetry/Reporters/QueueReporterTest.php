<?php
declare(strict_types=1);
namespace Tests\Engine\Telemetry\Reporters;
if (!defined('ATOMIC_START')) exit;
use PHPUnit\Framework\TestCase;
use Engine\Atomic\Queue\Managers\TelemetryManager;
use Engine\Atomic\Telemetry\Reporters\QueueReporter;
final class QueueReporterTest extends TestCase
{
    public function test_totals_include_failure_beyond_the_inventory_cap(): void
    {
        $manager = $this->createMock(TelemetryManager::class);
        $manager->method('fetch_all_jobs')->willReturnCallback(static function ($queue, $filters, $page, $limit): array {
            $jobs = array_fill(0, 200, ['state' => 'completed']);
            $jobs[] = ['state' => 'failed'];
            return ['items' => array_slice($jobs, 0, $limit), 'state_totals' => array_count_values(array_column($jobs, 'state'))];
        });
        $reporter = new QueueReporter($manager);
        self::assertCount(200, $reporter->jobs());
        self::assertSame(1, $reporter->snapshot()['failed']);
        self::assertSame(200, $reporter->snapshot()['completed']);
    }

    public function test_job_list_masks_secrets_in_payloads_like_the_json_endpoint(): void
    {
        $manager = $this->createMock(TelemetryManager::class);
        $manager->method('fetch_all_jobs')->willReturn(['items' => [
            'u1' => ['state' => 'failed', 'queue' => 'mail', 'payload' => ['handler' => 'Mail', 'password' => 'hunter2', 'api_key' => 'k-123']],
        ]]);
        $manager->method('fetch_events')->willReturn([['event_type_id' => 1, 'event_description' => 'token=abc123', 'created_at_formatted' => 'x', 'uuid_batch' => 'b']]);

        $reporter = new QueueReporter($manager);
        $job = $reporter->job_list()[0];

        self::assertSame('[MASKED]', $job->payload['password']);
        self::assertSame('[MASKED]', $job->payload['api_key']);
        self::assertSame('Mail', $job->title());
        self::assertStringNotContainsString('abc123', $reporter->events('db', 'u1')[0]->description);
    }
}
