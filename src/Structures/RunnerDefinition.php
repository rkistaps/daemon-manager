<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Structures;

use rkistaps\DaemonManager\Enums\RestartPolicy;

final readonly class RunnerDefinition
{
    /**
     * @param string $command Arguments for the runner script, split on whitespace. Each argument reaches the script
     *                        exactly as written: no shell interprets quotes, `;` or `$(...)` in it.
     * @param bool $isMonitor Marks the runner that restarts the others. startAll() starts it after every other
     *                        runner and stopAll() stops it first, so it never restarts a runner mid start or stop.
     */
    public function __construct(
        public string $name,
        public string $command,
        public RestartPolicy $restartPolicy = RestartPolicy::ON_FAILURE,
        public int $maxRestarts = 10,
        public int $restartDelaySeconds = 5,
        public bool $autoStart = true,
        public int $instanceCount = 1,
        public bool $logToFile = false,
        public bool $isMonitor = false
    ) {}

    /**
     * @return list<string>
     */
    public function commandArguments(): array
    {
        return preg_split('/\s+/', trim($this->command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'command' => $this->command,
            'restartPolicy' => $this->restartPolicy->value,
            'maxRestarts' => $this->maxRestarts,
            'restartDelaySeconds' => $this->restartDelaySeconds,
            'autoStart' => $this->autoStart,
            'instanceCount' => $this->instanceCount,
            'logToFile' => $this->logToFile,
            'isMonitor' => $this->isMonitor,
        ];
    }
}
