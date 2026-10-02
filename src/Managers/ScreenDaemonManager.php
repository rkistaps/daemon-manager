<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Managers;

use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;
use rkistaps\DaemonManager\Interfaces\RunnerRepositoryInterface;
use rkistaps\DaemonManager\Interfaces\StateStoreInterface;
use rkistaps\DaemonManager\Structures\RunnerDefinition;
use rkistaps\DaemonManager\Structures\RunnerState;
use rkistaps\DaemonManager\Structures\ScreenSettings;

final class ScreenDaemonManager implements DaemonManagerInterface
{
    private const STABLE_RUN_SECONDS = 600;
    private const LOG_LINES_IN_ERROR = 20;

    public function __construct(
        private readonly RunnerRepositoryInterface $runnerRepository,
        private readonly StateStoreInterface $stateStore,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem,
        private readonly ScreenSettings $settings = new ScreenSettings()
    ) {}

    public function startRunner(string $name): bool
    {
        $definition = $this->runnerRepository->getDefinitionByName($name);
        if (!$definition) {
            $this->logger->error('Daemon runner definition not found', ['name' => $name]);
            return false;
        }

        $success = true;
        $count = $definition->instanceCount;
        for ($i = 1; $i <= $count; $i++) {
            $instanceName = $count > 1 ? "{$name}-{$i}" : $name;
            $state = $this->stateStore->getState($instanceName);
            if ($state && $state->status === RunnerStatus::RUNNING) {
                $this->logger->warning('Daemon runner already running', ['name' => $instanceName]);
                continue;
            }
            try {
                $screenName = $this->settings->sessionName($instanceName);
                $logPath = $this->settings->logPath($instanceName);

                if ($definition->logToFile && !$this->filesystem->directoryExists($this->settings->logDirectory)) {
                    $this->filesystem->createDirectory($this->settings->logDirectory);
                }

                $command = $this->buildStartCommand($definition, $screenName, $logPath);

                $this->logger->info('Starting daemon runner', [
                    'name' => $instanceName,
                    'command' => $definition->command,
                    'screenName' => $screenName,
                    'logToFile' => $definition->logToFile,
                    'logPath' => $definition->logToFile ? $logPath : null,
                ]);

                $output = [];
                $returnCode = 0;
                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    $errorMessage = 'Failed to start screen session: ' . implode("\n", $output);
                    $this->logger->error('Failed to start daemon runner', [
                        'name' => $instanceName,
                        'error' => $errorMessage,
                        'returnCode' => $returnCode
                    ]);

                    $this->updateState($instanceName, RunnerStatus::FAILED, null, $screenName, $errorMessage);
                    $success = false;
                    continue;
                }

                $pid = $this->getScreenPid($screenName);

                $newState = new RunnerState(
                    name: $instanceName,
                    status: RunnerStatus::RUNNING,
                    pid: $pid,
                    screenName: $screenName,
                    restartCount: $state->restartCount ?? 0,
                    lastStarted: new DateTimeImmutable(),
                    lastStopped: $state?->lastStopped,
                    lastError: null,
                    nextRetryAt: null
                );

                $this->stateStore->saveState($newState);

                $this->logger->info('Daemon runner started successfully', [
                    'name' => $instanceName,
                    'pid' => $pid,
                    'screenName' => $screenName
                ]);

            } catch (\Throwable $e) {
                $this->logger->error('Exception while starting daemon runner', [
                    'name' => $instanceName,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);

                $this->updateState($instanceName, RunnerStatus::FAILED, null, null, $e->getMessage());
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Every variable part is quoted twice: once for the bash that screen runs, and once more for the shell exec()
     * runs, since the whole bash script is a single argument there. Nothing in a definition reaches a shell unquoted.
     */
    private function buildStartCommand(RunnerDefinition $definition, string $screenName, string $logPath): string
    {
        $runnerCommand = implode(' ', array_map(
            escapeshellarg(...),
            [$this->settings->runnerScript, ...$definition->commandArguments()]
        ));
        if ($definition->logToFile) {
            $runnerCommand .= sprintf(' > %s 2>&1', escapeshellarg($logPath));
        }

        $script = sprintf('cd %s && %s', escapeshellarg($this->settings->workingDirectory), $runnerCommand);

        return sprintf(
            '%s -dmS %s bash -c %s',
            escapeshellarg($this->settings->screenBinary),
            escapeshellarg($screenName),
            escapeshellarg($script)
        );
    }

    public function stopRunner(string $name): bool
    {
        $definition = $this->runnerRepository->getDefinitionByName($name);
        $count = $definition->instanceCount ?? 1;
        $success = true;
        for ($i = 1; $i <= $count; $i++) {
            $instanceName = $count > 1 ? "{$name}-{$i}" : $name;
            $state = $this->stateStore->getState($instanceName);
            if (!$state) {
                $this->logger->warning('Daemon runner state not found', ['name' => $instanceName]);
                $success = false;
                continue;
            }
            if ($state->status === RunnerStatus::STOPPED) {
                $this->logger->warning('Daemon runner already stopped', ['name' => $instanceName]);
                continue;
            }
            try {
                $this->logger->info('Stopping daemon runner', [
                    'name' => $instanceName,
                    'pid' => $state->pid,
                    'screenName' => $state->screenName
                ]);
                // While the process exits, a RUNNING state with a dead pid would look like a crash to the monitor.
                $this->updateState($instanceName, RunnerStatus::STOPPING);
                if ($state->screenName) {
                    $command = sprintf(
                        '%s -S %s -X quit',
                        escapeshellarg($this->settings->screenBinary),
                        escapeshellarg($state->screenName)
                    );
                    exec($command, $output, $returnCode);
                    if ($returnCode !== 0) {
                        $this->logger->warning('Failed to quit screen session gracefully', [
                            'name' => $instanceName,
                            'screenName' => $state->screenName,
                            'returnCode' => $returnCode
                        ]);
                    }
                }
                if ($state->pid) {
                    $this->terminateProcess($instanceName, $state->pid);
                }
                $updatedState = new RunnerState(
                    name: $state->name,
                    status: RunnerStatus::STOPPED,
                    pid: null,
                    screenName: null,
                    restartCount: $state->restartCount,
                    lastStarted: $state->lastStarted,
                    lastStopped: new DateTimeImmutable(),
                    lastError: null,
                    nextRetryAt: null
                );
                $this->stateStore->saveState($updatedState);
                $this->logger->info('Daemon runner stopped successfully', ['name' => $instanceName]);
            } catch (\Throwable $e) {
                $this->logger->error('Exception while stopping daemon runner', [
                    'name' => $instanceName,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $success = false;
            }
        }
        return $success;
    }

    /**
     * SIGTERM, then up to 10 seconds for a graceful exit, then SIGKILL.
     */
    private function terminateProcess(string $instanceName, int $pid): void
    {
        exec(sprintf('kill -TERM %d 2>/dev/null', $pid));

        $waitSeconds = 10;
        $exited = false;
        for ($j = 0; $j < $waitSeconds; $j++) {
            sleep(1);
            if (!$this->isPidRunning($pid)) {
                $exited = true;
                break;
            }
        }

        if (!$exited) {
            exec(sprintf('kill -KILL %d 2>/dev/null', $pid));
            $this->logger->warning('Force killed daemon runner after graceful timeout', [
                'name' => $instanceName,
                'pid' => $pid
            ]);
            return;
        }

        $this->logger->info('Daemon runner exited gracefully', [
            'name' => $instanceName,
            'pid' => $pid
        ]);
    }

    public function restartRunner(string $name): bool
    {
        $this->logger->info('Restarting daemon runner', ['name' => $name]);
        $definition = $this->runnerRepository->getDefinitionByName($name);
        $count = $definition->instanceCount ?? 1;
        $success = true;
        for ($i = 1; $i <= $count; $i++) {
            $instanceName = $count > 1 ? "{$name}-{$i}" : $name;
            $this->stopRunner($instanceName);
            sleep(2);
            if (!$this->startRunner($instanceName)) {
                $success = false;
            }
        }
        return $success;
    }

    public function startAll(): bool
    {
        $success = true;

        foreach ($this->definitionsInStartOrder() as $definition) {
            if ($definition->autoStart) {
                if (!$this->startRunner($definition->name)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function stopAll(): bool
    {
        $success = true;
        foreach (array_reverse($this->definitionsInStartOrder()) as $definition) {
            $count = $definition->instanceCount;
            for ($i = 1; $i <= $count; $i++) {
                $instanceName = $count > 1 ? "{$definition->name}-{$i}" : $definition->name;
                if (!$this->stopRunner($instanceName)) {
                    $success = false;
                }
            }
        }
        return $success;
    }

    /**
     * The monitor goes last: started earlier, its first check would start the runners start-all
     * has not reached yet. Stopped in reverse, so it can't restart runners stop-all just stopped.
     *
     * @return RunnerDefinition[]
     */
    private function definitionsInStartOrder(): array
    {
        $definitions = $this->runnerRepository->getAllDefinitions();
        usort($definitions, static fn(RunnerDefinition $a, RunnerDefinition $b): int => $a->isMonitor <=> $b->isMonitor);

        return $definitions;
    }

    public function getRunnerState(string $name): ?RunnerState
    {
        $state = $this->stateStore->getState($name);

        if ($state && $state->status === RunnerStatus::RUNNING) {
            if (!$this->isProcessRunning($state)) {
                $this->updateState($name, RunnerStatus::CRASHED, null, null, 'Process not found');
                return $this->stateStore->getState($name);
            }
        }

        return $state;
    }

    public function getAllRunnerStates(): array
    {
        $states = $this->stateStore->getAllStates();
        $result = [];

        foreach ($states as $state) {
            $result[] = $this->getRunnerState($state->name);
        }

        return array_values(array_filter($result));
    }

    public function checkAndRestartRunners(): void
    {
        $definitions = $this->runnerRepository->getAllDefinitions();

        foreach ($definitions as $definition) {
            $state = $this->getRunnerState($definition->name);

            if ($state !== null && $this->hasRunStably($state)) {
                $state = $this->resetRestartCount($state);
            }

            if ($this->shouldRestart($definition, $state)) {
                $this->handleRestart($definition, $state);
            }
        }
    }

    /**
     * maxRestarts guards against crash loops. Without a reset, restarts added up over the
     * runner's whole life and a daemon that crashed ten times in a month was never restarted again.
     */
    private function hasRunStably(RunnerState $state): bool
    {
        return $state->status === RunnerStatus::RUNNING
            && $state->restartCount > 0
            && $state->lastStarted !== null
            && $state->lastStarted <= new DateTimeImmutable(sprintf('-%d seconds', self::STABLE_RUN_SECONDS));
    }

    private function resetRestartCount(RunnerState $state): RunnerState
    {
        $resetState = new RunnerState(
            name: $state->name,
            status: $state->status,
            pid: $state->pid,
            screenName: $state->screenName,
            restartCount: 0,
            lastStarted: $state->lastStarted,
            lastStopped: $state->lastStopped,
            lastError: $state->lastError,
            nextRetryAt: $state->nextRetryAt
        );
        $this->stateStore->saveState($resetState);

        $this->logger->info('Daemon runner stable, restart count reset', [
            'name' => $state->name,
            'previousRestartCount' => $state->restartCount,
        ]);

        return $resetState;
    }

    private function shouldRestart(RunnerDefinition $definition, ?RunnerState $state): bool
    {
        if (!$state) {
            return $definition->autoStart;
        }

        // Mid start or stop: the process being absent is expected, not a failure.
        if (in_array($state->status, [RunnerStatus::STARTING, RunnerStatus::STOPPING], true)) {
            return false;
        }

        if ($state->nextRetryAt && new DateTimeImmutable() < $state->nextRetryAt) {
            return false;
        }

        return match ($definition->restartPolicy) {
            RestartPolicy::NEVER => false,
            RestartPolicy::ALWAYS => $state->status !== RunnerStatus::RUNNING,
            RestartPolicy::ON_FAILURE => in_array($state->status, [RunnerStatus::CRASHED, RunnerStatus::FAILED], true),
            RestartPolicy::UNLESS_STOPPED => $state->status !== RunnerStatus::STOPPED && $state->status !== RunnerStatus::RUNNING,
        };
    }

    private function handleRestart(RunnerDefinition $definition, ?RunnerState $state): void
    {
        $restartCount = $state->restartCount ?? 0;

        if ($restartCount >= $definition->maxRestarts) {
            $this->logger->warning('Maximum restart attempts reached', [
                'name' => $definition->name,
                'restartCount' => $restartCount,
                'maxRestarts' => $definition->maxRestarts
            ]);
            return;
        }

        $newRestartCount = $restartCount + 1;
        $this->logger->info('Attempting to restart daemon runner', [
            'name' => $definition->name,
            'attempt' => $newRestartCount,
            'maxRestarts' => $definition->maxRestarts
        ]);

        if ($this->startRunner($definition->name)) {
            $currentState = $this->stateStore->getState($definition->name);
            if ($currentState) {
                $updatedState = new RunnerState(
                    name: $currentState->name,
                    status: $currentState->status,
                    pid: $currentState->pid,
                    screenName: $currentState->screenName,
                    restartCount: $newRestartCount,
                    lastStarted: $currentState->lastStarted,
                    lastStopped: $currentState->lastStopped,
                    lastError: $currentState->lastError,
                    nextRetryAt: null
                );
                $this->stateStore->saveState($updatedState);
            }
        } else {
            // Exponential backoff, capped at 5 minutes
            $delay = min($definition->restartDelaySeconds * (2 ** $newRestartCount), 300);
            $nextRetry = (new DateTimeImmutable())->modify("+{$delay} seconds");

            $this->updateState(
                $definition->name,
                RunnerStatus::FAILED,
                null,
                null,
                'Restart failed',
                $newRestartCount,
                $nextRetry
            );
        }
    }

    private function updateState(
        string $name,
        RunnerStatus $status,
        ?int $pid = null,
        ?string $screenName = null,
        ?string $error = null,
        ?int $restartCount = null,
        ?DateTimeImmutable $nextRetryAt = null
    ): void {
        $currentState = $this->stateStore->getState($name);

        $lastError = $error;
        if (in_array($status, [RunnerStatus::CRASHED, RunnerStatus::FAILED], true)) {
            $logTail = $this->readLogTail($name);
            if ($logTail !== null) {
                $lastError = ($lastError ? $lastError . "\n" : '') . $logTail;
            }
        }
        $newState = new RunnerState(
            name: $name,
            status: $status,
            pid: $pid ?? $currentState?->pid,
            screenName: $screenName ?? $currentState?->screenName,
            restartCount: $restartCount ?? $currentState->restartCount ?? 0,
            lastStarted: $currentState?->lastStarted,
            lastStopped: $status === RunnerStatus::STOPPED ? new DateTimeImmutable() : $currentState?->lastStopped,
            lastError: $lastError,
            nextRetryAt: $nextRetryAt
        );

        $this->stateStore->saveState($newState);
    }

    private function readLogTail(string $instanceName): ?string
    {
        $logPath = $this->settings->logPath($instanceName);

        try {
            if (!$this->filesystem->fileExists($logPath)) {
                return null;
            }
            $lines = preg_split('/(?<=\n)/', $this->filesystem->read($logPath), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } catch (FilesystemException) {
            return null;
        }

        return implode('', array_slice($lines, -self::LOG_LINES_IN_ERROR));
    }

    private function getScreenPid(string $screenName): ?int
    {
        $output = shell_exec(sprintf('%s -list 2>/dev/null', escapeshellarg($this->settings->screenBinary)));

        if (is_string($output) && preg_match('/(\d+)\.' . preg_quote($screenName, '/') . '\s/', $output, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isProcessRunning(RunnerState $state): bool
    {
        return $state->pid !== null && $state->pid > 0 && $this->isPidRunning($state->pid);
    }

    private function isPidRunning(int $pid): bool
    {
        exec(sprintf('kill -0 %d 2>/dev/null', $pid), $output, $returnCode);

        return $returnCode === 0;
    }
}
