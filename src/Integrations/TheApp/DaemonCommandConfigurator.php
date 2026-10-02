<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Integrations\TheApp;

use League\CLImate\CLImate;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;
use rkistaps\DaemonManager\Interfaces\RunnerRepositoryInterface;
use rkistaps\DaemonManager\Services\MonitorService;
use TheApp\Components\CommandRunner;
use TheApp\Interfaces\CommandConfiguratorInterface;

/**
 * Registers the daemon commands with rkistaps/the-app. Needs rkistaps/the-app and league/climate, and a container that
 * resolves DaemonManagerInterface, RunnerRepositoryInterface and MonitorService.
 */
final class DaemonCommandConfigurator implements CommandConfiguratorInterface
{
    public function __construct(
        private readonly string $prefix = 'daemon'
    ) {}

    public function monitorCommand(): string
    {
        return $this->prefix . '/monitor';
    }

    public function configureCommands(CommandRunner $commandRunner): void
    {
        $commandRunner->addCommand($this->prefix . '/start', function (
            DaemonManagerInterface $manager,
            CLImate $climate,
            string $runner
        ): int {
            $climate->out("Starting daemon runner: <bold>{$runner}</bold>");

            if ($manager->startRunner($runner)) {
                $climate->green("✓ Runner '{$runner}' started successfully");
                return 0;
            }

            $climate->red("✗ Failed to start runner '{$runner}'");
            return 1;
        });

        $commandRunner->addCommand($this->prefix . '/stop', function (
            DaemonManagerInterface $manager,
            CLImate $climate,
            string $runner
        ): int {
            $climate->out("Stopping daemon runner: <bold>{$runner}</bold>");

            if ($manager->stopRunner($runner)) {
                $climate->green("✓ Runner '{$runner}' stopped successfully");
                return 0;
            }

            $climate->red("✗ Failed to stop runner '{$runner}'");
            return 1;
        });

        $commandRunner->addCommand($this->prefix . '/restart', function (
            DaemonManagerInterface $manager,
            CLImate $climate,
            string $runner
        ): int {
            $climate->out("Restarting daemon runner: <bold>{$runner}</bold>");

            if ($manager->restartRunner($runner)) {
                $climate->green("✓ Runner '{$runner}' restarted successfully");
                return 0;
            }

            $climate->red("✗ Failed to restart runner '{$runner}'");
            return 1;
        });

        $commandRunner->addCommand($this->prefix . '/start-all', function (
            DaemonManagerInterface $manager,
            CLImate $climate
        ): int {
            $climate->out('<bold>Starting all daemon runners...</bold>');

            if ($manager->startAll()) {
                $climate->green('✓ All runners started successfully');
                return 0;
            }

            $climate->yellow('⚠ Some runners may have failed to start. Check status for details.');
            return 0;
        });

        $commandRunner->addCommand($this->prefix . '/stop-all', function (
            DaemonManagerInterface $manager,
            CLImate $climate
        ): int {
            $climate->out('<bold>Stopping all daemon runners...</bold>');

            if ($manager->stopAll()) {
                $climate->green('✓ All runners stopped successfully');
                return 0;
            }

            $climate->yellow('⚠ Some runners may have failed to stop. Check status for details.');
            return 0;
        });

        $commandRunner->addCommand($this->prefix . '/restart-all', function (
            DaemonManagerInterface $manager,
            CLImate $climate
        ): int {
            $climate->out('<bold>Restarting all daemon runners...</bold>');
            $manager->stopAll();
            sleep(2);

            if ($manager->startAll()) {
                $climate->green('✓ All runners restarted successfully');
                return 0;
            }

            $climate->red('✗ Failed to restart all runners');
            return 1;
        });

        $commandRunner->addCommand($this->prefix . '/status', function (
            DaemonManagerInterface $manager,
            RunnerRepositoryInterface $repository,
            CLImate $climate
        ): int {
            $climate->out('<bold>Daemon Runner Status</bold>');
            $climate->br();

            $statesByName = [];
            foreach ($manager->getAllRunnerStates() as $state) {
                $statesByName[$state->name] = $state;
            }

            $tableData = [];
            foreach ($repository->getAllDefinitions() as $definition) {
                $state = $statesByName[$definition->name] ?? null;

                $tableData[] = [
                    'Name' => $definition->name,
                    'Status' => $state?->status->value ?? 'unknown',
                    'PID' => $state->pid ?? 'N/A',
                    'Restarts' => $state->restartCount ?? 0,
                    'Last Started' => $state?->lastStarted?->format('Y-m-d H:i:s') ?? 'Never',
                    'Command' => $definition->command,
                ];
            }

            if ($tableData === []) {
                $climate->yellow('No daemon runners configured.');
                return 0;
            }

            $climate->table($tableData);
            return 0;
        });

        $commandRunner->addCommand($this->prefix . '/status-detail', function (
            DaemonManagerInterface $manager,
            RunnerRepositoryInterface $repository,
            CLImate $climate,
            string $runner
        ): int {
            $definition = $repository->getDefinitionByName($runner);
            if (!$definition) {
                $climate->red("✗ Runner '{$runner}' not found in configuration");
                return 1;
            }

            $state = $manager->getRunnerState($runner);

            $climate->out("<bold>Daemon Runner Details: {$runner}</bold>");
            $climate->br();

            $climate->out('<bold>Configuration:</bold>');
            $climate->out("  Command: {$definition->command}");
            $climate->out("  Restart Policy: {$definition->restartPolicy->value}");
            $climate->out("  Max Restarts: {$definition->maxRestarts}");
            $climate->out("  Restart Delay: {$definition->restartDelaySeconds}s");
            $climate->out('  Auto Start: ' . ($definition->autoStart ? 'Yes' : 'No'));
            $climate->br();

            $climate->out('<bold>Current State:</bold>');
            if (!$state) {
                $climate->yellow('  No state information available');
                return 0;
            }

            $statusColor = match ($state->status) {
                RunnerStatus::RUNNING => 'green',
                RunnerStatus::STOPPED => 'yellow',
                RunnerStatus::CRASHED, RunnerStatus::FAILED => 'red',
                default => 'white'
            };

            $climate->out("  Status: <{$statusColor}>{$state->status->value}</{$statusColor}>");
            $climate->out('  PID: ' . ($state->pid ?? 'N/A'));
            $climate->out('  Screen Name: ' . ($state->screenName ?? 'N/A'));
            $climate->out("  Restart Count: {$state->restartCount}");
            $climate->out('  Last Started: ' . ($state->lastStarted?->format('Y-m-d H:i:s') ?? 'Never'));
            $climate->out('  Last Stopped: ' . ($state->lastStopped?->format('Y-m-d H:i:s') ?? 'Never'));

            if ($state->lastError) {
                $climate->out("  Last Error: <red>{$state->lastError}</red>");
            }

            if ($state->nextRetryAt) {
                $climate->out("  Next Retry: {$state->nextRetryAt->format('Y-m-d H:i:s')}");
            }

            return 0;
        });

        $commandRunner->addCommand($this->prefix . '/check-restart', function (
            DaemonManagerInterface $manager,
            CLImate $climate
        ): int {
            $climate->out('<bold>Checking and restarting failed daemon runners...</bold>');

            $manager->checkAndRestartRunners();

            $climate->green('✓ Check and restart completed');
            return 0;
        });

        $commandRunner->addCommand($this->prefix . '/list', function (
            RunnerRepositoryInterface $repository,
            CLImate $climate
        ): int {
            $definitions = $repository->getAllDefinitions();

            if ($definitions === []) {
                $climate->yellow('No daemon runners configured.');
                return 0;
            }

            $climate->out('<bold>Configured Daemon Runners:</bold>');
            $climate->br();

            $tableData = [];
            foreach ($definitions as $definition) {
                $tableData[] = [
                    'Name' => $definition->name,
                    'Command' => $definition->command,
                    'Policy' => $definition->restartPolicy->value,
                    'Max Restarts' => $definition->maxRestarts,
                    'Auto Start' => $definition->autoStart ? 'Yes' : 'No',
                ];
            }

            $climate->table($tableData);
            return 0;
        });

        // Runs until stopped. Register it as the runner definition marked isMonitor.
        $commandRunner->addCommand($this->monitorCommand(), function (
            MonitorService $monitorService,
            CLImate $climate
        ): int {
            $climate->out('<bold>Starting daemon monitor service...</bold>');
            $climate->out('Press Ctrl+C to stop');

            $monitorService->startMonitoring();
            return 0;
        });
    }
}
