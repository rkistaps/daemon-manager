<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Interfaces;

use rkistaps\DaemonManager\Structures\RunnerDefinition;

interface RunnerRepositoryInterface
{
    /**
     * @return RunnerDefinition[]
     */
    public function getAllDefinitions(): array;

    public function getDefinitionByName(string $name): ?RunnerDefinition;

    /**
     * Replaces any definition with the same name.
     */
    public function addDefinition(RunnerDefinition $definition): void;

    public function removeDefinition(string $name): bool;
}
