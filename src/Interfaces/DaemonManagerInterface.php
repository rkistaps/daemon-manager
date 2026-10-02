<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Interfaces;

use rkistaps\DaemonManager\Structures\RunnerState;

interface DaemonManagerInterface
{
    public function startRunner(string $name): bool;

    public function stopRunner(string $name): bool;

    public function restartRunner(string $name): bool;

    /**
     * Starts every runner with autoStart. The monitor starts last.
     */
    public function startAll(): bool;

    /**
     * Stops every runner. The monitor stops first.
     */
    public function stopAll(): bool;

    /**
     * A RUNNING state whose process is gone is marked CRASHED before it is returned.
     */
    public function getRunnerState(string $name): ?RunnerState;

    /**
     * @return RunnerState[]
     */
    public function getAllRunnerStates(): array;

    /**
     * Restarts crashed or failed runners according to their restart policies.
     */
    public function checkAndRestartRunners(): void;
}
