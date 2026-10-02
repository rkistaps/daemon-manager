<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Doubles;

use Closure;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;
use rkistaps\DaemonManager\Structures\RunnerState;

/**
 * Records calls and answers with the configured result, so tests of the manager's users never start a process.
 */
final class FakeDaemonManager implements DaemonManagerInterface
{
    /** @var list<string> "method" or "method:runner" for every call, in order */
    public array $calls = [];

    /** @var array<string, RunnerState> */
    public array $states = [];

    public bool $result = true;

    public ?Closure $onCheck = null;

    public function startRunner(string $name): bool
    {
        $this->calls[] = "startRunner:{$name}";
        return $this->result;
    }

    public function stopRunner(string $name): bool
    {
        $this->calls[] = "stopRunner:{$name}";
        return $this->result;
    }

    public function restartRunner(string $name): bool
    {
        $this->calls[] = "restartRunner:{$name}";
        return $this->result;
    }

    public function startAll(): bool
    {
        $this->calls[] = 'startAll';
        return $this->result;
    }

    public function stopAll(): bool
    {
        $this->calls[] = 'stopAll';
        return $this->result;
    }

    public function getRunnerState(string $name): ?RunnerState
    {
        return $this->states[$name] ?? null;
    }

    public function getAllRunnerStates(): array
    {
        return array_values($this->states);
    }

    public function checkAndRestartRunners(): void
    {
        $this->calls[] = 'checkAndRestartRunners';
        if ($this->onCheck !== null) {
            ($this->onCheck)();
        }
    }
}
