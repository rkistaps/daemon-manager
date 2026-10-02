<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Repositories\InMemoryRunnerRepository;
use rkistaps\DaemonManager\Structures\RunnerDefinition;

final class InMemoryRunnerRepositoryTest extends TestCase
{
    private InMemoryRunnerRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRunnerRepository();
    }

    public function testStartsEmpty(): void
    {
        $this->assertSame([], $this->repository->getAllDefinitions());
    }

    public function testAcceptsDefinitionsThroughConstructor(): void
    {
        $first = new RunnerDefinition('first-runner', 'first/command');
        $monitor = new RunnerDefinition('daemon-monitor', 'daemon/monitor', isMonitor: true);

        $repository = new InMemoryRunnerRepository([$first, $monitor]);

        $this->assertSame([$first, $monitor], $repository->getAllDefinitions());
    }

    public function testCanGetAllDefinitions(): void
    {
        $this->repository->addDefinition(new RunnerDefinition(
            name: 'worker-high',
            command: 'worker/run --queue=high --limit=100'
        ));

        $names = array_map(fn(RunnerDefinition $def) => $def->name, $this->repository->getAllDefinitions());

        $this->assertSame(['worker-high'], $names);
    }

    public function testCanGetDefinitionByName(): void
    {
        $this->repository->addDefinition(new RunnerDefinition(
            name: 'worker-high',
            command: 'worker/run --queue=high --limit=100'
        ));

        $definition = $this->repository->getDefinitionByName('worker-high');

        $this->assertInstanceOf(RunnerDefinition::class, $definition);
        $this->assertSame('worker-high', $definition->name);
        $this->assertSame('worker/run --queue=high --limit=100', $definition->command);
    }

    public function testReturnsNullForNonExistentDefinition(): void
    {
        $this->assertNull($this->repository->getDefinitionByName('non-existent'));
    }

    public function testCanAddDefinition(): void
    {
        $newDefinition = new RunnerDefinition(
            name: 'test-runner',
            command: 'test/command',
            restartPolicy: RestartPolicy::ALWAYS
        );

        $this->repository->addDefinition($newDefinition);

        $this->assertSame($newDefinition, $this->repository->getDefinitionByName('test-runner'));
    }

    public function testCanRemoveDefinition(): void
    {
        $this->repository->addDefinition(new RunnerDefinition('temp-runner', 'temp/command'));
        $this->assertNotNull($this->repository->getDefinitionByName('temp-runner'));

        $this->assertTrue($this->repository->removeDefinition('temp-runner'));

        $this->assertNull($this->repository->getDefinitionByName('temp-runner'));
    }

    public function testRemoveNonExistentDefinitionReturnsFalse(): void
    {
        $this->assertFalse($this->repository->removeDefinition('non-existent'));
    }

    public function testAddDefinitionOverwritesExisting(): void
    {
        $this->repository->addDefinition(new RunnerDefinition('test-runner', 'original/command'));
        $this->repository->addDefinition(new RunnerDefinition('test-runner', 'updated/command'));

        $this->assertSame('updated/command', $this->repository->getDefinitionByName('test-runner')?->command);
        $this->assertCount(1, $this->repository->getAllDefinitions());
    }
}
