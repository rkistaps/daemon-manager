<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Services;

use Psr\Log\LoggerInterface;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;

/**
 * Restarts crashed runners on an interval. Run it as the runner definition marked isMonitor.
 */
final class MonitorService
{
    private bool $running = false;

    public function __construct(
        private readonly DaemonManagerInterface $daemonManager,
        private readonly LoggerInterface $logger,
        private readonly int $checkIntervalSeconds = 30
    ) {}

    /**
     * Runs until SIGTERM or SIGINT when pcntl is available, otherwise until stop() or the process is killed.
     */
    public function startMonitoring(): void
    {
        $this->running = true;
        $this->logger->info('Daemon monitor service started');

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'stop']);
            pcntl_signal(SIGINT, [$this, 'stop']);
        }

        while ($this->running) {
            try {
                $this->performCheck();
            } catch (\Throwable $e) {
                $this->logger->error('Error during daemon monitoring check', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'exception' => $e,
                ]);
            }

            for ($i = 0; $i < $this->checkIntervalSeconds && $this->running; $i++) {
                sleep(1);

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        $this->logger->info('Daemon monitor service stopped');
    }

    public function performCheck(): void
    {
        $this->logger->debug('Performing daemon monitoring check');

        try {
            $this->daemonManager->checkAndRestartRunners();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to check and restart runners', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    public function stop(): void
    {
        $this->logger->info('Daemon monitor service stopping...');
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
