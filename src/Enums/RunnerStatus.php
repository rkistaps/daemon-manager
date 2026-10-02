<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Enums;

enum RunnerStatus: string
{
    case STOPPED = 'stopped';
    case STARTING = 'starting';
    case RUNNING = 'running';
    case STOPPING = 'stopping';
    case CRASHED = 'crashed';
    case FAILED = 'failed';
}
