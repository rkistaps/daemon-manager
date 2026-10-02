<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Structures;

use DateTimeImmutable;
use rkistaps\DaemonManager\Enums\RunnerStatus;

final class RunnerState
{
    public function __construct(
        public string $name,
        public RunnerStatus $status,
        public ?int $pid = null,
        public ?string $screenName = null,
        public int $restartCount = 0,
        public ?DateTimeImmutable $lastStarted = null,
        public ?DateTimeImmutable $lastStopped = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $nextRetryAt = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'pid' => $this->pid,
            'screenName' => $this->screenName,
            'restartCount' => $this->restartCount,
            'lastStarted' => $this->lastStarted?->format(DATE_ATOM),
            'lastStopped' => $this->lastStopped?->format(DATE_ATOM),
            'lastError' => $this->lastError,
            'nextRetryAt' => $this->nextRetryAt?->format(DATE_ATOM),
        ];
    }
}
