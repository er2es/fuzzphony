<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Registry;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\UnknownIndex;

/** Looks indexes up by name or by entity class. */
final class IndexRegistry
{
    /** @var array<string, IndexDefinition> */
    private array $byName = [];
    /** @var array<class-string, string> */
    private array $byClass = [];

    /** @param iterable<IndexDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(IndexDefinition $definition): void
    {
        DefinitionValidator::assertValid($definition);
        $this->byName[$definition->name] = $definition;
        if ($definition->entityClass !== null) {
            $this->byClass[$definition->entityClass] = $definition->name;
        }
    }

    public function get(string $nameOrClass): IndexDefinition
    {
        $name = $this->byClass[$nameOrClass] ?? $nameOrClass;

        return $this->byName[$name] ?? throw new UnknownIndex($nameOrClass, array_keys($this->byName));
    }

    public function has(string $nameOrClass): bool
    {
        return isset($this->byClass[$nameOrClass]) || isset($this->byName[$nameOrClass]);
    }

    /** @return array<string, IndexDefinition> */
    public function all(): array
    {
        return $this->byName;
    }
}
