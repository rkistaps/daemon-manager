<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Services;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use rkistaps\DaemonManager\Services\MonitorService;
use rkistaps\DaemonManager\Tests\Doubles\FakeDaemonManager;
use rkistaps\DaemonManager\Tests\Doubles\RecordingLogger;

final class MonitorServiceTest extends TestCase
{
    public function testPerformCheckRestartsFailedRunners(): void
    {
        $manager = new FakeDaemonManager();

        (new MonitorService($manager, new RecordingLogger()))->performCheck();

        $this->assertSame(['checkAndRestartRunners'], $manager->calls);
    }

    public function testPerformCheckLogsInsteadOfThrowing(): void
    {
        $manager = new FakeDaemonManager();
        $manager->onCheck = fn() => throw new RuntimeException('store unavailable');
        $logger = new RecordingLogger();

        (new MonitorService($manager, $logger))->performCheck();

        $this->assertContains('error: Failed to check and restart runners', $logger->records);
    }

    public function testMonitoringChecksUntilStopped(): void
    {
        $manager = new FakeDaemonManager();
        $logger = new RecordingLogger();
        $service = new MonitorService($manager, $logger);
        $manager->onCheck = function () use ($service): void {
            $this->assertTrue($service->isRunning());
            $service->stop();
        };

        $service->startMonitoring();

        $this->assertSame(['checkAndRestartRunners'], $manager->calls);
        $this->assertFalse($service->isRunning());
        $this->assertSame('info: Daemon monitor service stopped', end($logger->records));
    }
}
