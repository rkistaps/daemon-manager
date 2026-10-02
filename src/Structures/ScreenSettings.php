<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Structures;

final readonly class ScreenSettings
{
    public string $workingDirectory;

    /**
     * @param string $runnerScript Run from the working directory, with the definition's command as its arguments
     * @param string $sessionPrefix Prepended to the instance name to name its screen session
     * @param string $logDirectory Relative to the working directory and to the root of the manager's Filesystem,
     *                             which creates it, so root that Filesystem at the working directory
     * @param ?string $workingDirectory Defaults to the current directory when the settings are created
     */
    public function __construct(
        public string $screenBinary = '/usr/bin/screen',
        public string $runnerScript = './run',
        public string $sessionPrefix = 'daemon-',
        public string $logDirectory = 'data/daemon',
        ?string $workingDirectory = null
    ) {
        $this->workingDirectory = $workingDirectory ?? (getcwd() ?: '.');
    }

    public function sessionName(string $instanceName): string
    {
        return $this->sessionPrefix . $instanceName;
    }

    public function logPath(string $instanceName): string
    {
        return sprintf('%s/%s.log', rtrim($this->logDirectory, '/'), $instanceName);
    }
}
