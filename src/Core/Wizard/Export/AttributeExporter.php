<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard\Export;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;

/**
 * Emits the attributes to put on a Doctrine entity. Only table sources can be expressed with
 * attributes; for joined sources use YAML or the builder (supports() tells which).
 */
final class AttributeExporter
{
    public function supports(IndexDefinition $index): bool
    {
        return $index->source->table !== null && $index->watches === [];
    }

    public function export(IndexDefinition $index, string $className = 'Product'): string
    {
        if (!$this->supports($index)) {
            throw new \LogicException('Only table sources without extra watches can be expressed with attributes; export YAML instead.');
        }
        $e = static fn(mixed $v): string => var_export($v, true);

        $args = [sprintf('name: %s', $e($index->name)), sprintf('language: %s', $e($index->text->language))];
        if (!$index->text->unaccent) {
            $args[] = 'unaccent: false';
        }
        if ($index->sync !== SyncMode::Queue) {
            $args[] = sprintf('sync: SyncMode::%s', $index->sync->name);
        }
        if ($index->triggerLevel !== TriggerLevel::Statement) {
            $args[] = sprintf('triggerLevel: TriggerLevel::%s', $index->triggerLevel->name);
        }
        $args[] = sprintf('table: %s', $e($index->source->table));
        if ($index->boostColumn !== null) {
            $args[] = sprintf('boost: %s', $e($index->boostColumn));
        }
        if ($index->recencyColumn !== null) {
            $args[] = sprintf('recency: %s', $e($index->recencyColumn));
        }
        if ($index->tenant !== null) {
            $args[] = sprintf('tenant: %s', $e($index->tenant));
        }

        $properties = [];
        foreach ($index->fields as $field) {
            $fieldArgs = [$e($field->weight->value)];
            if ($field->fuzzy) {
                $fieldArgs[] = 'fuzzy: true';
            }
            if (!$field->highlight) {
                $fieldArgs[] = 'highlight: false';
            }
            $fieldArgs[] = sprintf('column: %s', $e($field->column()));
            $properties[$field->name][] = sprintf('#[SearchField(%s)]', implode(', ', $fieldArgs));
            $properties[$field->name]['type'] ??= '?string';
        }
        foreach ($index->filters as $filter) {
            $properties[$filter->name][] = sprintf('#[SearchFilter(type: %s, column: %s)]', $e($filter->type->value), $e($filter->column()));
            $properties[$filter->name]['type'] = match ($filter->type) {
                FilterType::Bool => '?bool',
                FilterType::Int => '?int',
                FilterType::Float => '?float',
                FilterType::String => '?string',
                FilterType::Date, FilterType::DateTime => '?\DateTimeImmutable',
            };
        }

        $body = [];
        foreach ($properties as $name => $lines) {
            $type = $lines['type'];
            unset($lines['type']);
            foreach ($lines as $line) {
                $body[] = '    ' . $line;
            }
            $body[] = sprintf('    public %s $%s = null;', $type, lcfirst(str_replace('_', '', ucwords((string) $name, '_'))));
            $body[] = '';
        }

        return sprintf(
            "<?php\n\nuse Fuzzphony\\Core\\Attribute\\Searchable;\nuse Fuzzphony\\Core\\Attribute\\SearchField;\nuse Fuzzphony\\Core\\Attribute\\SearchFilter;\nuse Fuzzphony\\Core\\Definition\\SyncMode;\nuse Fuzzphony\\Core\\Definition\\TriggerLevel;\n\n// Add these to your existing entity (keep your #[ORM\\...] mapping).\n#[Searchable(%s)]\nfinal class %s\n{\n%s}\n",
            implode(', ', $args),
            $className,
            rtrim(implode("\n", $body)) . "\n",
        );
    }
}
