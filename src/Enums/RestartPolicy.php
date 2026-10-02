<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Enums;

enum RestartPolicy: string
{
    case NEVER = 'never';
    case ON_FAILURE = 'on_failure';
    case ALWAYS = 'always';
    case UNLESS_STOPPED = 'unless_stopped';
}
