<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;

/**
 * @internal Reads the SQL of `source: { query_file: sql/catalog.sql }` / fromQueryFile(). The path is relative to a base directory (the
 * project root) and stays inside it, symbolic links resolved: a `..` that leaves it, or an absolute path outside it, is refused. The SQL
 * is read when the definition is loaded, so the file's content is the source query and part of the definition's fingerprint.
 */
final class QueryFile
{
    /** @throws InvalidDefinition */
    public static function read(string $index, string $path, ?string $baseDir = null): string
    {
        if (trim($path) === '') {
            throw new InvalidDefinition($index, ['"query_file" must be the path of an SQL file, got an empty string.']);
        }
        $base = realpath($baseDir ?? '.');
        if ($base === false) {
            throw new InvalidDefinition($index, [sprintf('Query file "%s": the base directory "%s" does not exist.', $path, $baseDir ?? '.')]);
        }
        $absolute = preg_match('#^(?:[A-Za-z]:)?[\\\\/]#', $path) === 1;
        $real = realpath($absolute ? $path : $base . DIRECTORY_SEPARATOR . $path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new InvalidDefinition($index, [sprintf('Query file "%s" does not exist or cannot be read (relative paths start at "%s").', $path, $base)]);
        }
        $prefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $inside = str_replace('\\', '/', $real);
        if (!str_starts_with(DIRECTORY_SEPARATOR === '/' ? $inside : strtolower($inside), DIRECTORY_SEPARATOR === '/' ? $prefix : strtolower($prefix))) {
            throw new InvalidDefinition($index, [sprintf('Query file "%s" is outside the project directory "%s"; keep it inside (a path with ".." or an absolute path elsewhere is refused).', $path, $base)]);
        }
        $sql = file_get_contents($real);
        if ($sql === false || trim($sql) === '') {
            throw new InvalidDefinition($index, [sprintf('Query file "%s" is empty.', $path)]);
        }

        return $sql;
    }
}
