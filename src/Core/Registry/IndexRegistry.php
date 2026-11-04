<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Registry;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexSource;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\UnknownIndex;

/** @internal Looks indexes up by name or by entity class. */
final class IndexRegistry
{
    /** @var array<string, IndexDefinition> */
    private array $byName = [];
    /** @var array<class-string, string> */
    private array $byClass = [];
    /** @var array<class-string, list<string>> every index that reads an entity class (as its entity or the entity of a source) */
    private array $allByClass = [];
    /** @var array<string, string> where an index came from (only the indexes registered with an origin) */
    private array $origins = [];

    /** @param iterable<IndexDefinition> $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    /**
     * @param ?string $origin where the definition comes from ("attribute Product", "definition class App\X"); given, the name must be free:
     *                        the same name from two sources is an error. Without an origin a registration replaces (an override, a plain list).
     *
     * @throws InvalidDefinition
     */
    public function register(IndexDefinition $definition, ?string $origin = null): void
    {
        DefinitionValidator::assertValid($definition);
        if ($origin !== null && isset($this->byName[$definition->name])) {
            throw new InvalidDefinition($definition->name, [sprintf('the index is defined twice: by %s and by %s.', $this->origins[$definition->name] ?? 'an earlier definition', $origin)]);
        }
        $this->byName[$definition->name] = $definition;
        if ($origin !== null) {
            $this->origins[$definition->name] = $origin;
        }
        // the first index of an entity answers for the entity class (the attribute index, then the definition classes); the others are reached by name
        foreach ([$definition->entityClass, ...array_map(static fn(IndexSource $s): ?string => $s->entityClass, $definition->sources)] as $class) {
            if ($class !== null && !in_array($definition->name, $this->allByClass[$class] ?? [], true)) {
                $this->allByClass[$class][] = $definition->name;
            }
            if ($class !== null && ($this->byClass[$class] ?? $definition->name) === $definition->name) {
                $this->byClass[$class] = $definition->name;
            }
        }
    }

    /** Records where a registered index came from, or adds to it ("YAML": the override of an attribute index). */
    public function annotateOrigin(string $name, string $note): void
    {
        $this->origins[$name] = isset($this->origins[$name]) ? $this->origins[$name] . ' + ' . $note : $note;
    }

    /** Where an index was defined, when known ("attribute App\Product", "definition class App\X", "YAML", "attribute App\Product + YAML"); null for an index given as an object. */
    public function origin(string $name): ?string
    {
        return $this->origins[$name] ?? null;
    }

    public function get(string $nameOrClass): IndexDefinition
    {
        $name = $this->byClass[$nameOrClass] ?? $nameOrClass;

        return $this->byName[$name] ?? throw new UnknownIndex($nameOrClass, array_keys($this->byName));
    }

    /**
     * EVERY index that reads an entity class (as its entity or as the entity of one of its sources), in registration order; get() answers for the first only.
     *
     * @return list<IndexDefinition>
     */
    public function allFor(string $class): array
    {
        $out = [];
        foreach ($this->allByClass[$class] ?? [] as $name) {
            $definition = $this->byName[$name];
            if ($definition->entityClass === $class || $definition->sourceForEntity($class) !== null) { // a later registration under the same name may have dropped the class
                $out[] = $definition;
            }
        }

        return $out;
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
