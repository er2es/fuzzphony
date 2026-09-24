<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Identifier;

final class AttributeDefinitionLoader
{
    public function __construct(private readonly NamingStrategy $naming = new ConventionNamingStrategy()) {}

    /** @param class-string $class */
    public function supports(string $class): bool
    {
        return (new \ReflectionClass($class))->getAttributes(Searchable::class) !== [];
    }

    /** @param class-string $class */
    public function load(string $class): IndexDefinition
    {
        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(Searchable::class);
        if ($attributes === []) {
            throw new InvalidDefinition($class, [sprintf('Class %s has no #[Searchable] attribute.', $class)]);
        }
        $searchable = $attributes[0]->newInstance();
        $name = $searchable->name ?? Identifier::snake($reflection->getShortName()) . 's';

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
