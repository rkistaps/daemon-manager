<?php

declare(strict_types=1);

namespace rkistaps\DaemonManager\Repositories;

use rkistaps\DaemonManager\Interfaces\RunnerRepositoryInterface;
use rkistaps\DaemonManager\Structures\RunnerDefinition;

final class InMemoryRunnerRepository implements RunnerRepositoryInterface
{
    /** @var array<string, RunnerDefinition> */
    private array $definitions = [];

    /**
     * @param RunnerDefinition[] $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->addDefinition($definition);
        }
    }

    public function getAllDefinitions(): array
    {
        return array_values($this->definitions);
    }

    public function getDefinitionByName(string $name): ?RunnerDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    public function addDefinition(RunnerDefinition $definition): void
    {
        $this->definitions[$definition->name] = $definition;
    }

    public function removeDefinition(string $name): bool
    {
        if (isset($this->definitions[$name])) {
            unset($this->definitions[$name]);
            return true;
        }
        return false;
    }
}
