# rkistaps/daemon-manager

Supervise long-running PHP CLI processes. Each runner runs in its own GNU `screen` session. The manager starts, stops,
restarts and reports on them, and a monitor process restarts the ones that crash according to their restart policy.
`DaemonLoopRunner` is a base class for writing the daemons themselves.

**Requirements:** PHP 8.3+, Linux with GNU `screen` installed (`apt install screen`), and a runner script that runs
your app's commands, such as `./run some/command`. The `pcntl` extension is optional: with it, daemons and the
monitor stop gracefully on SIGTERM and SIGINT.

## How it works

Starting a runner named `worker` with the command `worker/run` and the default settings runs:

```bash
'/usr/bin/screen' -dmS 'daemon-worker' bash -c 'cd '\''/srv/app'\'' && '\''./run'\'' '\''worker/run'\'''
```

That is, a detached screen session named `daemon-worker` running `./run worker/run` from `/srv/app`.

- The definition's command is split on whitespace and each argument is quoted, so it reaches your runner script exactly
  as written. Nothing in a definition is interpreted by a shell: `;`, `$(...)` and quotes are passed on literally.
- The session's pid and the runner's status are saved in a JSON state file (`data/daemon/runner-states.json` by default).
- A runner whose state is `running` but whose process is gone is reported as `crashed`.
- The monitor checks every 30 seconds and restarts `crashed` and `failed` runners according to their policy, with
  exponential backoff (`restartDelaySeconds * 2^attempt`, capped at 5 minutes) after a failed restart.
- `maxRestarts` stops a crash loop. The restart count resets once a runner has run for 10 minutes, so only repeated
  quick crashes reach the limit.
- Stopping a runner quits its screen session, sends SIGTERM, waits up to 10 seconds, then sends SIGKILL.
- `start-all` starts the monitor after every other runner, and `stop-all` stops it first, so it never restarts a runner
  that is in the middle of being started or stopped.

## Installation

```bash
composer require rkistaps/daemon-manager
```

## Defining runners

```php
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Structures\RunnerDefinition;

new RunnerDefinition(
    name: 'worker',                     // unique; also names the screen session and log file
    command: 'worker/run --queue=high', // arguments for the runner script
    restartPolicy: RestartPolicy::ON_FAILURE,
    maxRestarts: 10,
    restartDelaySeconds: 5,             // base delay for the backoff after a failed restart
    autoStart: true,                    // started by startAll() and, with no state yet, by the monitor
    instanceCount: 1,                   // more than 1 starts worker-1, worker-2, ...
    logToFile: false,                   // true sends output to data/daemon/worker.log
    isMonitor: false,                   // true for the one runner that runs the monitor
);
```

| Restart policy   | Restarts a runner that is                    |
|------------------|----------------------------------------------|
| `NEVER`          | never restarted                              |
| `ON_FAILURE`     | `crashed` or `failed`                        |
| `ALWAYS`         | anything but `running`                       |
| `UNLESS_STOPPED` | anything but `running` or explicitly stopped |

Runner statuses: `stopped`, `starting`, `running`, `stopping`, `crashed`, `failed`.

## Wiring it by hand

No container is needed:

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use rkistaps\DaemonManager\Managers\ScreenDaemonManager;
use rkistaps\DaemonManager\Repositories\InMemoryRunnerRepository;
use rkistaps\DaemonManager\Services\MonitorService;
use rkistaps\DaemonManager\Stores\FileStateStore;
use rkistaps\DaemonManager\Structures\ScreenSettings;

$appRoot = __DIR__;

// Root the filesystem at the app directory: the state file and log directory are relative to it.
$filesystem = new Filesystem(new LocalFilesystemAdapter($appRoot));

$repository = new InMemoryRunnerRepository([/* RunnerDefinition, ... */]);
$stateStore = new FileStateStore($filesystem);       // or new FileStateStore($filesystem, 'var/daemon-states.json')

$manager = new ScreenDaemonManager(
    $repository,
    $stateStore,
    $logger,                                          // any PSR-3 logger
    $filesystem,
    new ScreenSettings(
        screenBinary: '/usr/bin/screen',
        runnerScript: './run',
        sessionPrefix: 'myapp-',                      // sessions are named myapp-<runner>
        logDirectory: 'data/daemon',
        workingDirectory: $appRoot,                   // defaults to the current directory
    ),
);

$manager->startAll();
$manager->getRunnerState('worker')?->status;
$manager->stopRunner('worker');

// In the process run by the runner marked isMonitor:
(new MonitorService($manager, $logger))->startMonitoring();
```

All `ScreenSettings` arguments are optional. The defaults are `/usr/bin/screen`, `./run`, `daemon-`, `data/daemon` and
the current directory.

Each piece has an interface (`DaemonManagerInterface`, `RunnerRepositoryInterface`, `StateStoreInterface`), so you can
replace the in-memory repository or the JSON state store, for example with a database.

## Writing a daemon

Extend `DaemonLoopRunner` and implement `runIteration()`. `run()` calls it in a loop, logs anything it throws and
carries on, sleeps `intervalSeconds` between iterations, and returns after SIGTERM or SIGINT (with `pcntl`) or after
`stop()`.

```php
use Psr\Log\LoggerInterface;
use rkistaps\DaemonManager\Runners\DaemonLoopRunner;

final class QueueWorkerDaemon extends DaemonLoopRunner
{
    public function __construct(private QueueWorker $worker, LoggerInterface $logger)
    {
        parent::__construct($logger, intervalSeconds: 5);
    }

    protected function runIteration(): void
    {
        $this->worker->processBatch();
    }

    // Optional: sleep until a wall-clock boundary instead of a fixed pause
    protected function secondsUntilNextIteration(): int
    {
        return $this->intervalSeconds;
    }
}
```

Your app's runner script then needs a command that builds it and calls `run()`, such as `./run worker/run`.

## Command-line integration (rkistaps/the-app)

`rkistaps\DaemonManager\Integrations\TheApp\DaemonCommandConfigurator` registers console commands with
[rkistaps/the-app](https://github.com/rkistaps/the-app). It is optional, so install its dependencies yourself:

```bash
composer require rkistaps/the-app league/climate
```

Register it with your console app, and make sure the container resolves `DaemonManagerInterface`,
`RunnerRepositoryInterface` and `MonitorService`:

```php
use rkistaps\DaemonManager\Integrations\TheApp\DaemonCommandConfigurator;

TheApp\Factories\AppFactory::console($container)
    ->withCommandConfigurators([DaemonCommandConfigurator::class]);   // commands under daemon/
// or with another prefix:
//  ->withCommandConfigurators([new DaemonCommandConfigurator('svc')]);
```

| Command                                   | Does                                                  |
|-------------------------------------------|-------------------------------------------------------|
| `./run daemon/start --runner=<name>`      | Start a runner                                        |
| `./run daemon/stop --runner=<name>`       | Stop a runner                                         |
| `./run daemon/restart --runner=<name>`    | Stop then start a runner                              |
| `./run daemon/start-all`                  | Start every auto-start runner, the monitor last       |
| `./run daemon/stop-all`                   | Stop every runner, the monitor first                  |
| `./run daemon/restart-all`                | Stop all, then start all                              |
| `./run daemon/status`                     | Table of every runner's status, pid and restart count |
| `./run daemon/status-detail --runner=<n>` | One runner's configuration, state and last error      |
| `./run daemon/check-restart`              | Run one monitor check now                             |
| `./run daemon/list`                       | Table of the configured runners                       |
| `./run daemon/monitor`                    | Run the monitor until stopped                         |

The monitor command is `<prefix>/monitor`. Point the runner marked `isMonitor` at it.

## Example: an app with a few daemons

An app that keeps market candles in sync, works through a task queue, and has a monitor restarting either one if it
crashes:

```php
// config/dependencies.php (PHP-DI)
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use rkistaps\DaemonManager\Enums\RestartPolicy;
use rkistaps\DaemonManager\Interfaces\DaemonManagerInterface;
use rkistaps\DaemonManager\Interfaces\RunnerRepositoryInterface;
use rkistaps\DaemonManager\Interfaces\StateStoreInterface;
use rkistaps\DaemonManager\Managers\ScreenDaemonManager;
use rkistaps\DaemonManager\Repositories\InMemoryRunnerRepository;
use rkistaps\DaemonManager\Services\MonitorService;
use rkistaps\DaemonManager\Stores\FileStateStore;
use rkistaps\DaemonManager\Structures\RunnerDefinition;
use rkistaps\DaemonManager\Structures\ScreenSettings;

return [
    Filesystem::class => fn() => new Filesystem(new LocalFilesystemAdapter(APP_ROOT)),

    RunnerRepositoryInterface::class => fn() => new InMemoryRunnerRepository([
        new RunnerDefinition(
            name: 'candle-sync-daemon',
            command: 'candle-sync/daemon',
            restartDelaySeconds: 60,
        ),
        new RunnerDefinition(
            name: 'task-queue-daemon',
            command: 'task-queue/daemon',
            restartDelaySeconds: 60,
            instanceCount: 2,        // task-queue-daemon-1 and task-queue-daemon-2
            logToFile: true,         // data/daemon/task-queue-daemon-1.log, ...
        ),
        // Restarts crashed runners. Started after and stopped before all the others.
        new RunnerDefinition(
            name: 'daemon-monitor',
            command: 'daemon/monitor',
            restartDelaySeconds: 30,
            isMonitor: true,
        ),
    ]),

    StateStoreInterface::class => fn(ContainerInterface $c) => new FileStateStore($c->get(Filesystem::class)),

    DaemonManagerInterface::class => fn(ContainerInterface $c) => new ScreenDaemonManager(
        $c->get(RunnerRepositoryInterface::class),
        $c->get(StateStoreInterface::class),
        $c->get(LoggerInterface::class),
        $c->get(Filesystem::class),
        new ScreenSettings(sessionPrefix: 'myapp-', workingDirectory: APP_ROOT),
    ),

    MonitorService::class => fn(ContainerInterface $c) => new MonitorService(
        $c->get(DaemonManagerInterface::class),
        $c->get(LoggerInterface::class),
    ),
];
```

The daemons themselves extend `DaemonLoopRunner`, and the app registers `candle-sync/daemon` and `task-queue/daemon`
commands that build them and call `run()`. Then:

```bash
./run daemon/start-all     # candle sync, two task queue workers, then the monitor
./run daemon/status
screen -r myapp-candle-sync-daemon     # attach to a runner; detach with Ctrl-a d
tail -f data/daemon/task-queue-daemon-1.log
./run daemon/stop-all      # the monitor first, then the rest
```

## Operations

- **List sessions:** `screen -list`. Clean up dead ones with `screen -wipe`.
- **Reset all state:** stop everything, then delete the state file (`data/daemon/runner-states.json` by default).
- **A runner keeps failing:** `daemon/status-detail --runner=<name>` shows the last error, including the last 20 lines
  of its log when `logToFile` is on.

## Known limitations

Runners with `instanceCount` above 1 are started and stopped as a group, but the monitor and `restartRunner()` look
runners up by definition name, so they do not restart individual instances (`name-1`, `name-2`).

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests never run the real `screen`: they point `ScreenSettings` at `tests/_fixtures/fake-screen` and
`tests/_fixtures/fake-run`. They need `bash`, so run them on Linux or macOS, or in Docker:

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

## License

MIT
