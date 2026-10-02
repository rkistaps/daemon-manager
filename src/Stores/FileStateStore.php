<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Stores;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Interfaces\StateStoreInterface;
use rkistaps\DaemonManager\Structures\RunnerState;

final class FileStateStore implements StateStoreInterface
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $stateFile = 'data/daemon/runner-states.json'
    ) {}

    public function saveState(RunnerState $state): void
    {
        $states = $this->loadAllStates();
        $states[$state->name] = $state->toArray();
        $this->saveAllStates($states);
    }

    public function getState(string $name): ?RunnerState
    {
        $states = $this->loadAllStates();

        if (!isset($states[$name])) {
            return null;
        }

        return $this->arrayToState($states[$name]);
    }

    public function getAllStates(): array
    {
        $states = $this->loadAllStates();
        $result = [];

        foreach ($states as $stateData) {
            $state = $this->arrayToState($stateData);
            $result[$state->name] = $state;
        }

        return $result;
    }

    public function removeState(string $name): bool
    {
        $states = $this->loadAllStates();

        if (isset($states[$name])) {
            unset($states[$name]);
            $this->saveAllStates($states);
            return true;
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadAllStates(): array
    {
        try {
            if (!$this->filesystem->fileExists($this->stateFile)) {
                return [];
            }

            $states = json_decode($this->filesystem->read($this->stateFile), true);
            return is_array($states) ? $states : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $states
     */
    private function saveAllStates(array $states): void
    {
        $encoded = json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode daemon runner states as JSON');
        }

        $this->filesystem->write($this->stateFile, $encoded);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToState(array $data): RunnerState
    {
        return new RunnerState(
            name: $data['name'],
            status: RunnerStatus::from($data['status']),
            pid: $data['pid'] ?? null,
            screenName: $data['screenName'] ?? null,
            restartCount: $data['restartCount'] ?? 0,
            lastStarted: isset($data['lastStarted']) ? new DateTimeImmutable($data['lastStarted']) : null,
            lastStopped: isset($data['lastStopped']) ? new DateTimeImmutable($data['lastStopped']) : null,
            lastError: $data['lastError'] ?? null,
            nextRetryAt: isset($data['nextRetryAt']) ? new DateTimeImmutable($data['nextRetryAt']) : null
        );
    }
}
