<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** Resolves table / column names for attribute-based definitions (Doctrine metadata or conventions). */
interface NamingStrategy
{
    /** @param class-string $class */
    public function table(string $class): string;

    /** @param class-string $class */
    public function column(string $class, string $property): string;

    /** @param class-string $class */
    public function idColumn(string $class): string;

    /** @param class-string $class */
    public function idType(string $class): IdType;
}
