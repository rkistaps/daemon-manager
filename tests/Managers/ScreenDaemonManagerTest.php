<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Managers;

use Closure;
use DateTimeImmutable;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Enums\RunnerStatus;
use rkistaps\DaemonManager\Interfaces\StateStoreInterface;
use rkistaps\DaemonManager\Managers\ScreenDaemonManager;
use rkistaps\DaemonManager\Repositories\InMemoryRunnerRepository;
use rkistaps\DaemonManager\Stores\FileStateStore;
use rkistaps\DaemonManager\Structures\RunnerDefinition;
use rkistaps\DaemonManager\Structures\RunnerState;
use rkistaps\DaemonManager\Structures\ScreenSettings;

/**
 * screen is tests/_fixtures/fake-screen, which runs each session's command in the foreground, and the runner script is
 * tests/_fixtures/fake-run, which records the arguments it receives. The working directory's name has a space and
 * quotes in it, so the start command has to quote it.
 */
final class ScreenDaemonManagerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../_fixtures';

    private string $root;
    private Filesystem $filesystem;
    private FileStateStore $stateStore;
    private ScreenSettings $settings;

    /** @var string[] "name:status" for every saved state, in order */
    private array $savedStates = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . "/screen manager 'test' " . uniqid();
        mkdir($this->root);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->root));
        $this->stateStore = new FileStateStore($this->filesystem);
        $this->settings = new ScreenSettings(
            screenBinary: self::FIXTURES . '/fake-screen',
            runnerScript: self::FIXTURES . '/fake-run',
            sessionPrefix: 'test-',
            logDirectory: 'logs',
            workingDirectory: $this->root
        );

        putenv('FAKE_SCREEN_DIR=' . $this->root);
        putenv('FAKE_RUN_ARGS=' . $this->root . '/run-args');
        putenv('FAKE_SCREEN_PID');
        putenv('FAKE_SCREEN_EXIT_CODE');
    }

    protected function tearDown(): void
    {
        putenv('FAKE_SCREEN_DIR');
        putenv('FAKE_RUN_ARGS');
        putenv('FAKE_SCREEN_PID');
        putenv('FAKE_SCREEN_EXIT_CODE');
        (new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir())))->deleteDirectory(basename($this->root));
    }

    public function testResetsRestartCountOfRunnerThatHasRunStably(): void
    {
        $manager = $this->manager($this->definition('test-daemon'));
        $this->saveState('test-daemon', RunnerStatus::RUNNING, restartCount: 7, startedAgo: '-11 minutes');

        $manager->checkAndRestartRunners();

        $this->assertSame(0, $this->stateStore->getState('test-daemon')?->restartCount);
    }

    public function testKeepsRestartCountOfRecentlyRestartedRunner(): void
    {
        $manager = $this->manager($this->definition('test-daemon'));
        $this->saveState('test-daemon', RunnerStatus::RUNNING, restartCount: 7, startedAgo: '-2 minutes');

        $manager->checkAndRestartRunners();

        $this->assertSame(7, $this->stateStore->getState('test-daemon')?->restartCount);
    }

    public function testDoesNotRestartRunnerThatIsBeingStopped(): void
    {
        $manager = $this->manager($this->definition('test-daemon', RestartPolicy::ALWAYS));
        $this->saveState('test-daemon', RunnerStatus::STOPPING, restartCount: 0, startedAgo: '-1 hour');
        $this->savedStates = [];

        $manager->checkAndRestartRunners();

        $this->assertSame([], $this->savedStates);
    }

    public function testStopAllStopsMonitorFirstAndMarksEachRunnerStoppingBeforeStopped(): void
    {
        $manager = $this->manager(
            $this->definition('first-daemon'),
            $this->definition('daemon-monitor', isMonitor: true),
            $this->definition('second-daemon'),
        );
        foreach (['first-daemon', 'daemon-monitor', 'second-daemon'] as $name) {
            $this->saveState($name, RunnerStatus::RUNNING, restartCount: 0, startedAgo: '-1 hour', withProcess: false);
        }
        $this->savedStates = [];

        $this->assertTrue($manager->stopAll());

        $this->assertSame([
            'daemon-monitor:stopping', 'daemon-monitor:stopped',
            'second-daemon:stopping', 'second-daemon:stopped',
            'first-daemon:stopping', 'first-daemon:stopped',
        ], $this->savedStates);
    }

    public function testStartAllStartsMonitorLastAndSkipsRunnersWithoutAutoStart(): void
    {
        $manager = $this->manager(
            $this->definition('daemon-monitor', isMonitor: true),
            $this->definition('first-daemon'),
            $this->definition('manual-daemon', autoStart: false),
            $this->definition('second-daemon'),
        );

        $this->assertTrue($manager->startAll());

        $this->assertSame(['test-first-daemon', 'test-second-daemon', 'test-daemon-monitor'], $this->startedSessions());
    }

    public function testMonitorIsChosenByFlagNotByCommand(): void
    {
        $manager = $this->manager(
            $this->definition('flagged', command: 'anything/else', isMonitor: true),
            $this->definition('named-like-monitor', command: 'daemon/monitor'),
        );

        $manager->startAll();

        $this->assertSame(['test-named-like-monitor', 'test-flagged'], $this->startedSessions());
    }

    public function testStartRunsRunnerScriptInWorkingDirectoryThroughConfiguredScreen(): void
    {
        putenv('FAKE_SCREEN_PID=' . getmypid());
        $manager = $this->manager($this->definition('worker', command: 'worker/run --queue=high'));

        $this->assertTrue($manager->startRunner('worker'));

        $this->assertSame(['worker/run', '--queue=high'], $this->runnerArguments());
        $this->assertSame(realpath($this->root), trim((string) file_get_contents($this->root . '/run-args.cwd')));
        $this->assertSame(['test-worker'], $this->startedSessions());

        $state = $this->stateStore->getState('worker');
        $this->assertSame(RunnerStatus::RUNNING, $state?->status);
        $this->assertSame(getmypid(), $state->pid);
        $this->assertSame('test-worker', $state->screenName);
    }

    public function testCommandCannotInjectShellSyntax(): void
    {
        $command = <<<'CMD'
            worker/run --name="a b" 'single' ; touch injected-semicolon $(touch injected-subshell) `touch injected-backtick` && touch injected-and
            CMD;
        $manager = $this->manager($this->definition('worker', command: $command, logToFile: true));

        $this->assertTrue($manager->startRunner('worker'));

        $this->assertSame([
            'worker/run', '--name="a', 'b"', "'single'", ';', 'touch', 'injected-semicolon',
            '$(touch', 'injected-subshell)', '`touch', 'injected-backtick`', '&&', 'touch', 'injected-and',
        ], $this->runnerArguments());
        foreach ([$this->root, getcwd(), self::FIXTURES] as $directory) {
            $this->assertSame([], glob($directory . '/injected-*'), "Injected command ran in {$directory}");
        }
    }

    public function testLogToFileRedirectsOutputIntoLogDirectory(): void
    {
        $manager = $this->manager($this->definition('worker', logToFile: true));

        $this->assertTrue($manager->startRunner('worker'));

        $this->assertSame("fake run: started\n", $this->filesystem->read('logs/worker.log'));
    }

    public function testWithoutLogToFileNoLogIsWritten(): void
    {
        $manager = $this->manager($this->definition('worker'));

        $this->assertTrue($manager->startRunner('worker'));

        $this->assertFalse($this->filesystem->directoryExists('logs'));
    }

    public function testInstancesAreNamedWithNumberSuffix(): void
    {
        $manager = $this->manager($this->definition('worker', instanceCount: 2));

        $this->assertTrue($manager->startRunner('worker'));

        $this->assertSame(['test-worker-1', 'test-worker-2'], $this->startedSessions());
        $this->assertSame(['worker-1', 'worker-2'], array_keys($this->stateStore->getAllStates()));
    }

    public function testFailedStartMarksRunnerFailedWithScreenOutputAndLogTail(): void
    {
        putenv('FAKE_SCREEN_EXIT_CODE=3');
        $this->filesystem->write('logs/worker.log', "earlier line\nlast line before crash\n");
        $manager = $this->manager($this->definition('worker', logToFile: true));

        $this->assertFalse($manager->startRunner('worker'));

        $state = $this->stateStore->getState('worker');
        $this->assertSame(RunnerStatus::FAILED, $state?->status);
        $this->assertSame(
            "Failed to start screen session: fake screen: cannot start session\nearlier line\nlast line before crash\n",
            $state->lastError
        );
    }

    public function testStopQuitsScreenSessionThroughConfiguredBinary(): void
    {
        $manager = $this->manager($this->definition('worker'));
        $this->stateStore->saveState(new RunnerState('worker', RunnerStatus::RUNNING, screenName: 'test-worker'));

        $this->assertTrue($manager->stopRunner('worker'));

        $this->assertContains(['-S', 'test-worker', '-X', 'quit'], $this->screenCalls());
        $state = $this->stateStore->getState('worker');
        $this->assertSame(RunnerStatus::STOPPED, $state?->status);
        $this->assertNull($state->screenName);
    }

    public function testCrashedRunnerIsRestartedAndCounted(): void
    {
        $manager = $this->manager($this->definition('worker'));
        $this->saveState('worker', RunnerStatus::CRASHED, restartCount: 2, startedAgo: '-1 minute', withProcess: false);

        $manager->checkAndRestartRunners();

        $this->assertSame(['test-worker'], $this->startedSessions());
        $state = $this->stateStore->getState('worker');
        $this->assertSame(RunnerStatus::RUNNING, $state?->status);
        $this->assertSame(3, $state->restartCount);
    }

    public function testRunnerAtMaxRestartsIsNotRestarted(): void
    {
        $manager = $this->manager($this->definition('worker'));
        $this->saveState('worker', RunnerStatus::CRASHED, restartCount: 10, startedAgo: '-1 minute', withProcess: false);

        $manager->checkAndRestartRunners();

        $this->assertSame([], $this->startedSessions());
    }

    public function testFailedRestartSchedulesRetryWithBackoff(): void
    {
        putenv('FAKE_SCREEN_EXIT_CODE=1');
        $manager = $this->manager($this->definition('worker'));
        $this->saveState('worker', RunnerStatus::CRASHED, restartCount: 1, startedAgo: '-1 minute', withProcess: false);

        $manager->checkAndRestartRunners();

        $state = $this->stateStore->getState('worker');
        $this->assertSame(RunnerStatus::FAILED, $state?->status);
        $this->assertSame(2, $state->restartCount);
        // restartDelaySeconds 30 * 2^2, capped at 300
        $this->assertEqualsWithDelta(time() + 120, $state->nextRetryAt?->getTimestamp(), 5);
    }

    public function testNeverPolicyDoesNotRestartCrashedRunner(): void
    {
        $manager = $this->manager($this->definition('worker', RestartPolicy::NEVER));
        $this->saveState('worker', RunnerStatus::CRASHED, restartCount: 0, startedAgo: '-1 minute', withProcess: false);

        $manager->checkAndRestartRunners();

        $this->assertSame([], $this->startedSessions());
    }

    private function manager(RunnerDefinition ...$definitions): ScreenDaemonManager
    {
        return new ScreenDaemonManager(
            new InMemoryRunnerRepository($definitions),
            $this->recordingStore(),
            new NullLogger(),
            $this->filesystem,
            $this->settings
        );
    }

    private function definition(
        string $name,
        RestartPolicy $restartPolicy = RestartPolicy::ON_FAILURE,
        string $command = 'test/daemon',
        bool $autoStart = true,
        int $instanceCount = 1,
        bool $logToFile = false,
        bool $isMonitor = false,
    ): RunnerDefinition {
        return new RunnerDefinition(
            name: $name,
            command: $command,
            restartPolicy: $restartPolicy,
            maxRestarts: 10,
            restartDelaySeconds: 30,
            autoStart: $autoStart,
            instanceCount: $instanceCount,
            logToFile: $logToFile,
            isMonitor: $isMonitor
        );
    }

    /**
     * With a process, the test process itself stands in for the daemon so the liveness check sees it running.
     * Without one, stopping sends no signals.
     */
    private function saveState(
        string $name,
        RunnerStatus $status,
        int $restartCount,
        string $startedAgo,
        bool $withProcess = true,
    ): void {
        $this->recordingStore()->saveState(new RunnerState(
            name: $name,
            status: $status,
            pid: $withProcess ? (getmypid() ?: null) : null,
            screenName: $withProcess ? $this->settings->sessionName($name) : null,
            restartCount: $restartCount,
            lastStarted: new DateTimeImmutable($startedAgo),
            lastStopped: null,
            lastError: null,
            nextRetryAt: null
        ));
    }

    /**
     * @return list<string>
     */
    private function startedSessions(): array
    {
        return $this->lines($this->root . '/sessions');
    }

    /**
     * @return list<string>
     */
    private function runnerArguments(): array
    {
        return $this->lines($this->root . '/run-args');
    }

    /**
     * @return list<list<string>> The arguments of each call to screen
     */
    private function screenCalls(): array
    {
        $calls = [];
        $call = null;
        foreach ($this->lines($this->root . '/calls') as $line) {
            if ($line === '--- call') {
                if ($call !== null) {
                    $calls[] = $call;
                }
                $call = [];
                continue;
            }
            $call[] = $line;
        }
        if ($call !== null) {
            $calls[] = $call;
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function lines(string $path): array
    {
        return is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    private function recordingStore(): StateStoreInterface
    {
        $record = function (RunnerState $state): void {
            $this->savedStates[] = $state->name . ':' . $state->status->value;
        };

        return new class ($this->stateStore, $record) implements StateStoreInterface {
            public function __construct(private readonly StateStoreInterface $inner, private readonly Closure $record)
            {
            }

            public function saveState(RunnerState $state): void
            {
                ($this->record)($state);
                $this->inner->saveState($state);
            }

            public function getState(string $name): ?RunnerState
            {
                return $this->inner->getState($name);
            }

            public function getAllStates(): array
            {
                return $this->inner->getAllStates();
            }

            public function removeState(string $name): bool
            {
                return $this->inner->removeState($name);
            }
        };
    }
}
