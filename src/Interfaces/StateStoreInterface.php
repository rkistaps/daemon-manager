<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Interfaces;

use rkistaps\DaemonManager\Structures\RunnerState;

interface StateStoreInterface
{
    public function saveState(RunnerState $state): void;

    public function getState(string $name): ?RunnerState;

    /**
     * @return array<string, RunnerState> Keyed by runner name
     */
    public function getAllStates(): array;

    public function removeState(string $name): bool;
}
