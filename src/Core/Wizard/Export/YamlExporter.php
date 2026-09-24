<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard\Export;

use Fuzzphony\Core\Definition\IndexDefinition;

/** Writes config/packages/fuzzphony.yaml content without depending on symfony/yaml. */
final class YamlExporter
{
    public function __construct(private readonly ArrayExporter $arrays = new ArrayExporter()) {}

    public function export(IndexDefinition $index): string
    {
        return "fuzzphony:\n  indexes:\n" . $this->node([$index->name => $this->arrays->export($index)], 2);
    }

    /** @param array<array-key, mixed> $data */
    private function node(array $data, int $depth): string
    {
        $out = '';
        $pad = str_repeat('  ', $depth);
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $out .= $value === [] ? sprintf("%s%s: {}\n", $pad, $key) : sprintf("%s%s:\n%s", $pad, $key, $this->node($value, $depth + 1));
            } elseif (is_string($value) && str_contains($value, "\n")) {
                $out .= sprintf("%s%s: |\n%s\n", $pad, $key, implode("\n", array_map(static fn (string $l): string => $pad . '  ' . $l, explode("\n", $value))));
            } else {
                $out .= sprintf("%s%s: %s\n", $pad, $key, $this->scalar($value));
            }
        }

        return $out;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0',
            $value === null => '~',
            default => preg_match('/^[A-Za-z_][A-Za-z0-9_ .\/-]*$/', (string) $value) === 1 && !in_array(strtolower((string) $value), ['yes', 'no', 'on', 'off', 'true', 'false', 'null', 'y', 'n'], true)
                ? (string) $value
                : "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }
}
