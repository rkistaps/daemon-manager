<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Runners;

use Psr\Log\LoggerInterface;

/**
 * Base for daemon loops. run() calls runIteration() until SIGTERM or SIGINT, logging anything it throws, and sleeps
 * between iterations. Without pcntl the loop runs until the process is killed.
 *
 * ```php
 * final class MyDaemon extends DaemonLoopRunner
 * {
 *     protected function runIteration(): void
 *     {
 *         // periodic work
 *     }
 * }
 *
 * (new MyDaemon($logger, 30))->run();
 * ```
 */
abstract class DaemonLoopRunner
{
    private bool $shouldStop = false;

    public function __construct(
        protected LoggerInterface $logger,
        protected int $intervalSeconds = 60
    ) {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }
    }

    public function run(): void
    {
        $this->logger->info('Daemon loop started');
        while (!$this->shouldStop) {
            try {
                $this->runIteration();
            } catch (\Throwable $e) {
                $this->logger->error('Daemon iteration failed', [
                    'message' => $e->getMessage(),
                    'stack' => $e->getTraceAsString(),
                    'exception' => $e,
                ]);
            }
            if ($this->shouldStop) {
                break;
            }
            $sleepSeconds = $this->secondsUntilNextIteration();
            $this->logger->debug('Sleeping', ['interval' => $sleepSeconds]);
            sleep($sleepSeconds);
        }
        $this->logger->info('Daemon stopped gracefully');
    }

    /**
     * Ends the loop after the current iteration, as SIGTERM and SIGINT do.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    abstract protected function runIteration(): void;

    /**
     * Called after each iteration. Override to align iterations to wall-clock boundaries instead of a fixed pause.
     */
    protected function secondsUntilNextIteration(): int
    {
        return $this->intervalSeconds;
    }
}
