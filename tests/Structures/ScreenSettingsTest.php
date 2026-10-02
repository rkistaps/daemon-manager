<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Tests\Structures;

use PHPUnit\Framework\TestCase;
use rkistaps\DaemonManager\Structures\ScreenSettings;

final class ScreenSettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new ScreenSettings();

        $this->assertSame('/usr/bin/screen', $settings->screenBinary);
        $this->assertSame('./run', $settings->runnerScript);
        $this->assertSame('daemon-', $settings->sessionPrefix);
        $this->assertSame('data/daemon', $settings->logDirectory);
    }

    public function testWorkingDirectoryDefaultsToCurrentDirectoryAtConstruction(): void
    {
        $original = getcwd();
        $this->assertNotFalse($original);

        $settings = new ScreenSettings();
        chdir(sys_get_temp_dir());
        try {
            $this->assertSame($original, $settings->workingDirectory);
        } finally {
            chdir($original);
        }
    }

    public function testAcceptsCustomValues(): void
    {
        $settings = new ScreenSettings(
            screenBinary: '/opt/bin/screen',
            runnerScript: 'bin/console',
            sessionPrefix: 'acme-',
            logDirectory: 'var/log/daemons',
            workingDirectory: '/srv/acme'
        );

        $this->assertSame('/opt/bin/screen', $settings->screenBinary);
        $this->assertSame('bin/console', $settings->runnerScript);
        $this->assertSame('acme-', $settings->sessionPrefix);
        $this->assertSame('var/log/daemons', $settings->logDirectory);
        $this->assertSame('/srv/acme', $settings->workingDirectory);
    }

    public function testSessionNameAddsPrefix(): void
    {
        $this->assertSame('acme-worker-2', (new ScreenSettings(sessionPrefix: 'acme-'))->sessionName('worker-2'));
    }

    public function testLogPathIsInLogDirectory(): void
    {
        $this->assertSame('data/daemon/worker.log', (new ScreenSettings())->logPath('worker'));
        $this->assertSame('var/log/worker.log', (new ScreenSettings(logDirectory: 'var/log/'))->logPath('worker'));
    }
}
