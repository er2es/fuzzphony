<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Identifier;

/** @internal Builds a definition from #[Searchable] attributes on a Doctrine/plain entity class. */
final readonly class AttributeDefinitionLoader
{
    public function __construct(private NamingStrategy $naming = new ConventionNamingStrategy()) {}

    /** @param class-string $class */
    public function supports(string $class): bool
    {
        return (new \ReflectionClass($class))->getAttributes(Searchable::class) !== [];
    }

    /** @param class-string $class */
    public function load(string $class): IndexDefinition
    {
        return $this->loadAll($class)[0];
    }

    /**
     * One definition per #[Searchable] of the class (the attribute is repeatable): from the second on, each carries a `name` of its own.
     *
     * @param class-string $class
     *
     * @return non-empty-list<IndexDefinition>
     */
    public function loadAll(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Searchable::class);
        if ($attributes === []) {
            throw new InvalidDefinition($class, [sprintf('Class %s has no #[Searchable] attribute.', $class)]);
        }
        $searchables = [];
        $names = [];
        foreach ($attributes as $position => $attribute) {
            $searchable = $attribute->newInstance();
            $name = $searchable->name ?? Identifier::snake($reflection->getShortName()) . 's';
            if ($position > 0 && $searchable->name === null) {
                throw new InvalidDefinition($name, [sprintf('Class %s repeats #[Searchable]: every one after the first needs its own `name` (the first one is "%s").', $class, array_key_first($names))]);
            }
            if (isset($names[$name])) {
                throw new InvalidDefinition($name, [sprintf('Class %s has two #[Searchable] named "%s" (attributes %d and %d): give each its own `name`.', $class, $name, $names[$name], $position + 1)]);
            }
            $names[$name] = $position + 1;
            $searchables[$name] = $searchable;
        }

        $definitions = [];
        foreach ($searchables as $name => $searchable) {
            $definitions[] = $this->build($class, $reflection, $searchable, $name);
        }

        return $definitions;
    }

    /**
     * @param class-string     $class
     * @param \ReflectionClass<object> $reflection
     */
    private function build(string $class, \ReflectionClass $reflection, Searchable $searchable, string $name): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)
            ->fromTable($searchable->table ?? $this->naming->table($class), $this->naming->idColumn($class))
            ->idType($this->naming->idType($class))
            ->sync($searchable->sync)
            ->triggerLevel($searchable->triggerLevel)
            ->language($searchable->language, $searchable->unaccent)
            ->entity($class);

        if ($searchable->boost !== null) {
            $builder->boostBy($searchable->boost);
        }
        if ($searchable->recency !== null) {
            $builder->recencyBy($searchable->recency);
        }
        if ($searchable->tenant !== null) {
            $builder->tenant($searchable->tenant);
        }
        if (!$searchable->log) {
            $builder->log(false);
        }
        if ($searchable->synonymsFile !== null || $searchable->synonyms !== []) {
            $builder->synonyms([...$searchable->synonymsFile !== null ? Synonyms::fromFile($searchable->synonymsFile, $name)->toEntries() : [], ...$searchable->synonyms]);
        }

        $problems = [];
        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(SearchField::class) as $attribute) {
                $field = $attribute->newInstance();
                $builder->field(
                    Identifier::snake($property->getName()),
                    $field->weight,
                    $field->fuzzy,
                    $field->highlight,
                    $field->column ?? $this->naming->column($class, $property->getName()),
                    $field->granted,
                    $field->code,
                );
            }
            foreach ($property->getAttributes(SearchFilter::class) as $attribute) {
                $filter = $attribute->newInstance();
                $type = $filter->type;
                if ($type === null) {
                    $propertyType = $property->getType();
                    $type = $propertyType instanceof \ReflectionNamedType ? FilterType::fromPhpType($propertyType->getName()) : null;
                    if ($type === null) {
                        $problems[] = sprintf(
                            'Cannot infer the filter type of %s::$%s; pass it explicitly: #[SearchFilter(type: "int")].',
                            $reflection->getShortName(),
                            $property->getName(),
                        );
                        continue;
                    }
                }
                $builder->filter(
                    Identifier::snake($property->getName()),
                    $type,
                    $filter->column ?? $this->naming->column($class, $property->getName()),
                );
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition($name, $problems);
        }

        return $builder->build();
    }
}
