<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;

/** @internal Turns a class with #[AsIndexDefinition] and a build(IndexBuilder): void method into an IndexDefinition. */
final class IndexDefinitionClass
{
    /** @param class-string $class instantiated without arguments */
    public static function fromClass(string $class): IndexDefinition
    {
        if (!class_exists($class)) {
            throw new InvalidDefinition($class, [sprintf('"%s" is not a class that can be loaded.', $class)]);
        }

        return self::fromInstance(new $class());
    }

    public static function fromInstance(object $instance): IndexDefinition
    {
        $class = $instance::class;
        $attributes = (new \ReflectionClass($class))->getAttributes(AsIndexDefinition::class);
        if ($attributes === []) {
            throw new InvalidDefinition($class, [sprintf('"%s" has no #[AsIndexDefinition] attribute.', $class)]);
        }
        $attribute = $attributes[0]->newInstance();
        if (!method_exists($instance, 'build')) {
            throw new InvalidDefinition($attribute->name, [sprintf('"%s" has no build(IndexBuilder $builder): void method.', $class)]);
        }
        $builder = new IndexBuilder($attribute->name);
        if ($attribute->entity !== null) {
            $builder->entity($attribute->entity);
        }
        $instance->build($builder);

        return $builder->build();
    }
}
