<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Stores;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Stores\FileStateStore;
use rkistaps\DaemonManager\Structures\RunnerState;

final class FileStateStoreTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/file-state-store-test-' . uniqid();
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
    }

    protected function tearDown(): void
    {
        (new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())))->deleteDirectory(basename($this->root));
    }

    public function testSavedStateReadsBackWithAllFields(): void
    {
        $store = new FileStateStore($this->filesystem);
        $started = new DateTimeImmutable('2025-01-01T12:00:00+00:00');
        $store->saveState(new RunnerState(
            name: 'worker',
            status: RunnerStatus::FAILED,
            pid: 4321,
            screenName: 'daemon-worker',
            restartCount: 2,
            lastStarted: $started,
            lastStopped: $started->modify('+1 minute'),
            lastError: 'boom',
            nextRetryAt: $started->modify('+2 minutes')
        ));

        $state = $store->getState('worker');

        $this->assertNotNull($state);
        $this->assertSame([
            'name' => 'worker',
            'status' => 'failed',
            'pid' => 4321,
            'screenName' => 'daemon-worker',
            'restartCount' => 2,
            'lastStarted' => '2025-01-01T12:00:00+00:00',
            'lastStopped' => '2025-01-01T12:01:00+00:00',
            'lastError' => 'boom',
            'nextRetryAt' => '2025-01-01T12:02:00+00:00',
        ], $state->toArray());
    }

    public function testWritesToDefaultStateFile(): void
    {
        (new FileStateStore($this->filesystem))->saveState(new RunnerState('worker', RunnerStatus::RUNNING));

        $this->assertTrue($this->filesystem->fileExists('data/daemon/runner-states.json'));
    }

    public function testWritesToConfiguredStateFile(): void
    {
        (new FileStateStore($this->filesystem, 'var/states.json'))->saveState(new RunnerState('worker', RunnerStatus::RUNNING));

        $this->assertTrue($this->filesystem->fileExists('var/states.json'));
        $this->assertFalse($this->filesystem->fileExists('data/daemon/runner-states.json'));
    }

    public function testGetAllStatesIsKeyedByName(): void
    {
        $store = new FileStateStore($this->filesystem);
        $store->saveState(new RunnerState('first', RunnerStatus::RUNNING));
        $store->saveState(new RunnerState('second', RunnerStatus::STOPPED));

        $states = $store->getAllStates();

        $this->assertSame(['first', 'second'], array_keys($states));
        $this->assertSame(RunnerStatus::STOPPED, $states['second']->status);
    }

    public function testRemoveState(): void
    {
        $store = new FileStateStore($this->filesystem);
        $store->saveState(new RunnerState('worker', RunnerStatus::RUNNING));

        $this->assertTrue($store->removeState('worker'));
        $this->assertFalse($store->removeState('worker'));
        $this->assertNull($store->getState('worker'));
    }

    public function testUnreadableStateFileReadsAsEmpty(): void
    {
        $this->filesystem->write('data/daemon/runner-states.json', 'not json');

        $store = new FileStateStore($this->filesystem);

        $this->assertSame([], $store->getAllStates());
        $this->assertNull($store->getState('worker'));
    }
}
