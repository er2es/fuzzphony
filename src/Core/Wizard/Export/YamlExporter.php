<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard\Export;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Support\Coerce;

/** @internal Writes config/packages/fuzzphony.yaml content without depending on symfony/yaml. */
final class YamlExporter
{
    private readonly ArrayExporter $arrays;

    public function __construct()
    {
        $this->arrays = new ArrayExporter();
    }

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
                $out .= match (true) {
                    $value === [] => sprintf("%s%s: {}\n", $pad, $key),
                    array_is_list($value) && self::scalars($value) => sprintf("%s%s: %s\n", $pad, $key, $this->flow($value)),
                    array_is_list($value) => sprintf("%s%s:\n%s", $pad, $key, $this->sequence($value, $depth + 1)),
                    default => sprintf("%s%s:\n%s", $pad, $key, $this->node($value, $depth + 1)),
                };
            } elseif (is_string($value) && str_contains($value, "\n")) {
                $out .= $this->multiline($pad, $key . ':', $value);
            } else {
                $out .= sprintf("%s%s: %s\n", $pad, $key, $this->scalar($value));
            }
        }

        return $out;
    }

    /**
     * A text of several lines as a block scalar that reads back as the same text: `|-` has no final newline, `|` one, `|+` more. A text that
     * starts with a blank, or has a line made of blanks only, would need an indentation indicator: it is written as a double-quoted string.
     */
    private function multiline(string $pad, string $label, string $value): string
    {
        $body = rtrim($value, "\n");
        $trailing = strlen($value) - strlen($body);
        if ($body === '' || preg_match('/^[ \t]|\r/', $body) === 1) {
            return sprintf("%s%s %s\n", $pad, $label, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $indicator = match ($trailing) {
            0 => '|-',
            1 => '|',
            default => '|+',
        };
        $lines = array_map(static fn(string $l): string => $l === '' ? '' : $pad . '  ' . $l, explode("\n", $body));

        return sprintf("%s%s %s\n%s\n%s", $pad, $label, $indicator, implode("\n", $lines), $trailing > 1 ? str_repeat("\n", $trailing - 1) : '');
    }

    /**
     * A list as a YAML block sequence ("- item"): a list of words stays on one line, a longer item (a map, a list of lists) goes below its dash.
     *
     * @param list<mixed> $items
     */
    private function sequence(array $items, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $out = '';
        foreach ($items as $item) {
            $out .= match (true) {
                !is_array($item) => sprintf("%s- %s\n", $pad, $this->scalar($item)),
                $item === [] => sprintf("%s- {}\n", $pad),
                array_is_list($item) && self::scalars($item) => sprintf("%s- %s\n", $pad, $this->flow($item)),
                array_is_list($item) => sprintf("%s-\n%s", $pad, $this->sequence($item, $depth + 1)),
                default => sprintf("%s-\n%s", $pad, $this->node($item, $depth + 1)),
            };
        }

        return $out;
    }

    /** @param list<mixed> $items */
    private function flow(array $items): string
    {
        return '[' . implode(', ', array_map($this->scalar(...), $items)) . ']';
    }

    /** @param array<array-key, mixed> $items */
    private static function scalars(array $items): bool
    {
        return array_all($items, static fn(mixed $item): bool => !is_array($item));
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::floatLiteral($value),
            default => self::stringLiteral(Coerce::str($value)),
        };
    }

    private static function floatLiteral(float $value): string
    {
        $trimmed = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $trimmed !== '' ? $trimmed : '0';
    }

    private static function stringLiteral(string $value): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_ .\/-]*$/', $value) === 1 && !in_array(strtolower($value), ['yes', 'no', 'on', 'off', 'true', 'false', 'null', 'y', 'n'], true)
            ? $value
            : "'" . str_replace("'", "''", $value) . "'";
    }
}
