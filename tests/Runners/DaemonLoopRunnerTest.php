<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Runners;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use rkistaps\DaemonManager\Runners\DaemonLoopRunner;
use rkistaps\DaemonManager\Tests\Doubles\RecordingLogger;

final class DaemonLoopRunnerTest extends TestCase
{
    public function testRunsIterationsUntilStoppedAndLogsFailures(): void
    {
        $logger = new RecordingLogger();
        $runner = new class ($logger) extends DaemonLoopRunner {
            public int $iterations = 0;

            public function __construct(LoggerInterface $logger)
            {
                parent::__construct($logger, 0);
            }

            protected function runIteration(): void
            {
                $this->iterations++;
                if ($this->iterations === 1) {
                    throw new RuntimeException('first pass fails');
                }
                if ($this->iterations === 3) {
                    $this->stop();
                }
            }
        };

        $runner->run();

        $this->assertSame(3, $runner->iterations);
        $this->assertSame([
            'info: Daemon loop started',
            'error: Daemon iteration failed',
            'debug: Sleeping',
            'debug: Sleeping',
            'info: Daemon stopped gracefully',
        ], $logger->records);
    }

    public function testSleepComesFromSecondsUntilNextIteration(): void
    {
        $logger = new RecordingLogger();
        $runner = new class ($logger) extends DaemonLoopRunner {
            /** @var list<int> */
            public array $requestedSleeps = [];

            protected function runIteration(): void
            {
                if (count($this->requestedSleeps) === 2) {
                    $this->stop();
                }
            }

            protected function secondsUntilNextIteration(): int
            {
                $this->requestedSleeps[] = 0;
                return 0;
            }
        };

        $runner->run();

        $this->assertSame([0, 0], $runner->requestedSleeps);
    }
}
