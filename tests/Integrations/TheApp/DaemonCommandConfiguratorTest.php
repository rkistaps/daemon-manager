<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Integrations\TheApp;

use DI\ContainerBuilder;
use League\CLImate\CLImate;
use League\CLImate\Util\Writer\Buffer;
use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Integrations\TheApp\DaemonCommandConfigurator;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;
use rkistaps\DaemonManager\Interfaces\RunnerRepositoryInterface;
use rkistaps\DaemonManager\Repositories\InMemoryRunnerRepository;
use rkistaps\DaemonManager\Structures\RunnerDefinition;
use rkistaps\DaemonManager\Structures\RunnerState;
use rkistaps\DaemonManager\Tests\Doubles\FakeDaemonManager;
use TheApp\Apps\ConsoleApp;
use TheApp\Components\Output\BufferedOutput;
use TheApp\Factories\AppFactory;

final class DaemonCommandConfiguratorTest extends TestCase
{
    private FakeDaemonManager $manager;
    private Buffer $buffer;
    private ConsoleApp $app;

    protected function setUp(): void
    {
        $this->manager = new FakeDaemonManager();
        $this->buffer = new Buffer();
        $climate = new CLImate();
        $climate->output->add('buffer', $this->buffer);
        $climate->output->defaultTo('buffer');

        $container = (new ContainerBuilder())->addDefinitions([
            DaemonManagerInterface::class => $this->manager,
            RunnerRepositoryInterface::class => new InMemoryRunnerRepository([
                new RunnerDefinition('worker', 'worker/run --queue=high'),
                new RunnerDefinition('svc-monitor', 'svc/monitor', isMonitor: true),
            ]),
            CLImate::class => $climate,
        ])->build();

        $this->app = AppFactory::console($container)
            ->withCommandConfigurators([new DaemonCommandConfigurator('svc')])
            ->withOutput(new BufferedOutput());
    }

    public function testMonitorCommandUsesPrefix(): void
    {
        $this->assertSame('svc/monitor', (new DaemonCommandConfigurator('svc'))->monitorCommand());
        $this->assertSame('daemon/monitor', (new DaemonCommandConfigurator())->monitorCommand());
    }

    public function testStartPassesRunnerToManager(): void
    {
        $this->assertSame(0, $this->app->run(['console.php', 'svc/start', '--runner=worker']));

        $this->assertSame(['startRunner:worker'], $this->manager->calls);
        $this->assertStringContainsString("Runner 'worker' started successfully", $this->buffer->get());
    }

    public function testFailedStopExitsWithOne(): void
    {
        $this->manager->result = false;

        $this->assertSame(1, $this->app->run(['console.php', 'svc/stop', '--runner=worker']));

        $this->assertSame(['stopRunner:worker'], $this->manager->calls);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function managerCommands(): iterable
    {
        yield 'restart' => [['svc/restart', '--runner=worker'], ['restartRunner:worker']];
        yield 'start-all' => [['svc/start-all'], ['startAll']];
        yield 'stop-all' => [['svc/stop-all'], ['stopAll']];
        yield 'check-restart' => [['svc/check-restart'], ['checkAndRestartRunners']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expectedCalls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('managerCommands')]
    public function testCommandCallsManager(array $arguments, array $expectedCalls): void
    {
        $this->assertSame(0, $this->app->run(['console.php', ...$arguments]));

        $this->assertSame($expectedCalls, $this->manager->calls);
    }

    public function testListShowsConfiguredRunners(): void
    {
        $this->assertSame(0, $this->app->run(['console.php', 'svc/list']));

        $this->assertStringContainsString('worker/run --queue=high', $this->buffer->get());
        $this->assertStringContainsString('svc-monitor', $this->buffer->get());
    }

    public function testStatusShowsEachRunnersState(): void
    {
        $this->manager->states['worker'] = new RunnerState('worker', RunnerStatus::RUNNING, pid: 4321);

        $this->assertSame(0, $this->app->run(['console.php', 'svc/status']));

        $this->assertMatchesRegularExpression('/worker\s*\|\s*running\s*\|\s*4321/', $this->buffer->get());
        $this->assertMatchesRegularExpression('/svc-monitor\s*\|\s*unknown/', $this->buffer->get());
    }

    public function testStatusDetailOfUnknownRunnerExitsWithOne(): void
    {
        $this->assertSame(1, $this->app->run(['console.php', 'svc/status-detail', '--runner=missing']));

        $this->assertStringContainsString("Runner 'missing' not found", $this->buffer->get());
    }

    public function testStatusDetailShowsDefinitionAndState(): void
    {
        $this->manager->states['worker'] = new RunnerState('worker', RunnerStatus::CRASHED, lastError: 'boom');

        $this->assertSame(0, $this->app->run(['console.php', 'svc/status-detail', '--runner=worker']));

        $this->assertStringContainsString('Restart Policy: on_failure', $this->buffer->get());
        $this->assertStringContainsString('crashed', $this->buffer->get());
        $this->assertStringContainsString('boom', $this->buffer->get());
    }

    public function testCommandsUseTheConfiguredPrefix(): void
    {
        $this->assertSame(1, $this->app->run(['console.php', 'daemon/start-all']));

        $this->assertSame([], $this->manager->calls);
    }
}
