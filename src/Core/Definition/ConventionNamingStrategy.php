<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Support\Identifier;

/** Product::$createdAt -> table "product", column "created_at", id "id" (typed by the $id property). */
final class ConventionNamingStrategy implements NamingStrategy
{
    public function table(string $class): string
    {
        return Identifier::snake((new \ReflectionClass($class))->getShortName());
    }

    public function column(string $class, string $property): string
    {
        return Identifier::snake($property);
    }

    public function idColumn(string $class): string
    {
        return 'id';
    }

    public function idType(string $class): IdType
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasProperty('id')) {
            return IdType::Int;
        }
        $type = $reflection->getProperty('id')->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'int' ? IdType::Int : IdType::String;
    }
}
