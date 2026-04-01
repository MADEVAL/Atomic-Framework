<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry;

use Engine\Atomic\Telemetry\Queue\EventType;
use PHPUnit\Framework\TestCase;

class EventTypeTest extends TestCase
{
    public function test_job_created(): void
    {
        $this->assertSame(1, EventType::JOB_CREATED->value);
    }

    public function test_job_fetched(): void
    {
        $this->assertSame(2, EventType::JOB_FETCHED->value);
    }

    public function test_job_success(): void
    {
        $this->assertSame(3, EventType::JOB_SUCCESS->value);
    }

    public function test_job_failed(): void
    {
        $this->assertSame(4, EventType::JOB_FAILED->value);
    }

    public function test_job_retried(): void
    {
        $this->assertSame(5, EventType::JOB_RETRIED->value);
    }

    public function test_job_incomplete_handled(): void
    {
        $this->assertSame(6, EventType::JOB_INCOMPLETE_HANDLED->value);
    }

    public function test_all_descriptions_non_empty(): void
    {
        foreach (EventType::cases() as $case) {
            $this->assertNotEmpty($case->description(), "Description for {$case->name} should not be empty");
        }
    }

    public function test_all_values_unique(): void
    {
        $values = array_map(fn(EventType $e) => $e->value, EventType::cases());
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function test_from_valid(): void
    {
        $this->assertSame(EventType::JOB_CREATED, EventType::from(1));
        $this->assertSame(EventType::JOB_SUCCESS, EventType::from(3));
    }

    public function test_try_from_invalid(): void
    {
        $this->assertNull(EventType::tryFrom(999));
    }

    public function test_description_content(): void
    {
        $this->assertStringContainsString('created', EventType::JOB_CREATED->description());
        $this->assertStringContainsString('fetched', EventType::JOB_FETCHED->description());
        $this->assertStringContainsString('success', EventType::JOB_SUCCESS->description());
        $this->assertStringContainsString('failure', EventType::JOB_FAILED->description());
        $this->assertStringContainsString('retried', EventType::JOB_RETRIED->description());
    }

    public function test_cases_count(): void
    {
        $this->assertCount(6, EventType::cases());
    }
}
