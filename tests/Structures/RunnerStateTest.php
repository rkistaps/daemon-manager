<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Structures;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Structures\RunnerState;

final class RunnerStateTest extends TestCase
{
    public function testCanCreateState(): void
    {
        $now = new DateTimeImmutable();

        $state = new RunnerState(
            name: 'test-runner',
            status: RunnerStatus::RUNNING,
            pid: 12345,
            screenName: 'test-screen',
            restartCount: 2,
            lastStarted: $now,
            lastStopped: null,
            lastError: null,
            nextRetryAt: null
        );

        $this->assertSame('test-runner', $state->name);
        $this->assertSame(RunnerStatus::RUNNING, $state->status);
        $this->assertSame(12345, $state->pid);
        $this->assertSame('test-screen', $state->screenName);
        $this->assertSame(2, $state->restartCount);
        $this->assertSame($now, $state->lastStarted);
        $this->assertNull($state->lastStopped);
        $this->assertNull($state->lastError);
        $this->assertNull($state->nextRetryAt);
    }

    public function testToArrayIncludesAllFields(): void
    {
        $now = new DateTimeImmutable('2025-01-01 12:00:00+00:00');

        $state = new RunnerState(
            name: 'test-runner',
            status: RunnerStatus::CRASHED,
            pid: 12345,
            screenName: 'test-screen',
            restartCount: 3,
            lastStarted: $now,
            lastStopped: $now->modify('+5 minutes'),
            lastError: 'Test error message',
            nextRetryAt: $now->modify('+10 minutes')
        );

        $array = $state->toArray();

        $this->assertSame('test-runner', $array['name']);
        $this->assertSame('crashed', $array['status']);
        $this->assertSame(12345, $array['pid']);
        $this->assertSame('test-screen', $array['screenName']);
        $this->assertSame(3, $array['restartCount']);
        $this->assertSame('2025-01-01T12:00:00+00:00', $array['lastStarted']);
        $this->assertSame('2025-01-01T12:05:00+00:00', $array['lastStopped']);
        $this->assertSame('Test error message', $array['lastError']);
        $this->assertSame('2025-01-01T12:10:00+00:00', $array['nextRetryAt']);
    }

    public function testToArrayHandlesNullValues(): void
    {
        $state = new RunnerState(
            name: 'test-runner',
            status: RunnerStatus::STOPPED
        );

        $array = $state->toArray();

        $this->assertSame('test-runner', $array['name']);
        $this->assertSame('stopped', $array['status']);
        $this->assertNull($array['pid']);
        $this->assertNull($array['screenName']);
        $this->assertSame(0, $array['restartCount']);
        $this->assertNull($array['lastStarted']);
        $this->assertNull($array['lastStopped']);
        $this->assertNull($array['lastError']);
        $this->assertNull($array['nextRetryAt']);
    }
}
