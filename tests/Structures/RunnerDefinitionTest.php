<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Structures;

use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Structures\RunnerDefinition;

final class RunnerDefinitionTest extends TestCase
{
    public function testCanCreateDefinition(): void
    {
        $definition = new RunnerDefinition(
            name: 'test-runner',
            command: 'test/command',
            restartPolicy: RestartPolicy::ON_FAILURE,
            maxRestarts: 5,
            restartDelaySeconds: 10,
            autoStart: true
        );

        $this->assertSame('test-runner', $definition->name);
        $this->assertSame('test/command', $definition->command);
        $this->assertSame(RestartPolicy::ON_FAILURE, $definition->restartPolicy);
        $this->assertSame(5, $definition->maxRestarts);
        $this->assertSame(10, $definition->restartDelaySeconds);
        $this->assertTrue($definition->autoStart);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $definition = new RunnerDefinition(
            name: 'test-runner',
            command: 'test/command'
        );

        $expected = [
            'name' => 'test-runner',
            'command' => 'test/command',
            'restartPolicy' => 'on_failure',
            'maxRestarts' => 10,
            'restartDelaySeconds' => 5,
            'autoStart' => true,
            'instanceCount' => 1,
            'logToFile' => false,
            'isMonitor' => false,
        ];

        $this->assertSame($expected, $definition->toArray());
    }

    public function testIsMonitorOnlyWhenFlagged(): void
    {
        $this->assertTrue((new RunnerDefinition('any-name', 'any/command', isMonitor: true))->isMonitor);
        $this->assertFalse((new RunnerDefinition('daemon-monitor', 'daemon/monitor'))->isMonitor);
    }

    public function testDefaultValues(): void
    {
        $definition = new RunnerDefinition(
            name: 'test-runner',
            command: 'test/command'
        );

        $this->assertSame(RestartPolicy::ON_FAILURE, $definition->restartPolicy);
        $this->assertSame(10, $definition->maxRestarts);
        $this->assertSame(5, $definition->restartDelaySeconds);
        $this->assertTrue($definition->autoStart);
        $this->assertSame(1, $definition->instanceCount);
        $this->assertFalse($definition->logToFile);
        $this->assertFalse($definition->isMonitor);
    }

    public function testCommandArgumentsSplitOnWhitespace(): void
    {
        $definition = new RunnerDefinition('test-runner', "  worker/run --queue=high\t--limit=5  ");

        $this->assertSame(['worker/run', '--queue=high', '--limit=5'], $definition->commandArguments());
    }

    public function testEmptyCommandHasNoArguments(): void
    {
        $this->assertSame([], (new RunnerDefinition('test-runner', '   '))->commandArguments());
    }
}
