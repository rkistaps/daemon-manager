<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Doubles;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> "level: message" for every record, in order */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = sprintf('%s: %s', is_string($level) ? $level : '?', $message);
    }
}
