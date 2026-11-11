<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * @internal The `returnable` declaration of an index or source: a plain name returns the column of that name, `alias => column`
 * returns the column under another name (Hit::$data is keyed by the alias).
 */
final class Returnable
{
    /**
     * @param array<array-key, mixed> $entries a list of names, an `alias => column` map, or both mixed (a list entry is its own alias)
     * @param array<string, string>   $known   the names already declared (name => column), to detect a name given two meanings
     *
     * @return array{map: array<string, string>, clashes: list<string>} name => column, in declaration order
     */
    public static function normalise(array $entries, array $known = []): array
    {
        $map = $known;
        $clashes = [];
        foreach ($entries as $key => $column) {
            if (!is_string($column)) {
                continue;
            }
            $name = is_string($key) ? $key : $column;
            if (isset($map[$name]) && $map[$name] !== $column) {
                $clashes[] = sprintf('Returnable name "%s" is declared with two different columns ("%s" and "%s").', $name, $map[$name], $column);

                continue;
            }
            $map[$name] = $column;
        }

        return ['map' => $map, 'clashes' => $clashes];
    }

    /**
     * Splits a name => column map into what IndexDefinition stores: the plain names (alias = column) and the real aliases.
     *
     * @param array<string, string> $map
     *
     * @return array{plain: list<string>, aliases: array<string, string>}
     */
    public static function split(array $map): array
    {
        $plain = [];
        $aliases = [];
        foreach ($map as $name => $column) {
            if ($name === $column) {
                $plain[] = $name;
            } else {
                $aliases[$name] = $column;
            }
        }

        return ['plain' => $plain, 'aliases' => $aliases];
    }
}
