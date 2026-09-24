<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard\Export;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Ranking\RankingProfile;

/** Emits the equivalent fluent builder code (plain PHP projects, tests). */
final class BuilderExporter
{
    public function export(IndexDefinition $index): string
    {
        $e = static fn(mixed $v): string => var_export($v, true);
        $lines = [sprintf('$%s = IndexDefinition::builder(%s)', $this->variable($index->name), $e($index->name))];
        $source = $index->source;
        $lines[] = $source->query !== null
            ? sprintf("    ->fromQuery(<<<'SQL'\n        %s\n        SQL%s)", str_replace("\n", "\n        ", $source->query), $source->idColumn !== 'id' ? ', ' . $e($source->idColumn) : '')
            : sprintf('    ->fromTable(%s%s)', $e($source->table), $source->idColumn !== 'id' ? ', ' . $e($source->idColumn) : '');
        if ($index->idType->value !== 'int') {
            $lines[] = sprintf('    ->idType(%s)', $e($index->idType->value));
        }
        foreach ($index->watches as $watch) {
            $args = [$e($watch->table), $e($watch->affectedIds)];
            if ($watch->columns !== null) {
                if ($watch->keyColumn !== 'id') {
                    $args[] = $e($watch->keyColumn);
                }
                $args[] = 'columns: ' . $e($watch->columns);
            } elseif ($watch->keyColumn !== 'id') {
                $args[] = $e($watch->keyColumn);
            }
            $lines[] = sprintf('    ->watch(%s)', implode(', ', $args));
        }
        foreach ($index->fields as $field) {
            $args = [$e($field->name), $e($field->weight->value)];
            if ($field->fuzzy) {
                $args[] = 'fuzzy: true';
            }
            if (!$field->highlight) {
                $args[] = 'highlight: false';
            }
            if ($field->column !== null) {
                $args[] = 'column: ' . $e($field->column);
            }
            $lines[] = sprintf('    ->field(%s)', implode(', ', $args));
        }
        foreach ($index->filters as $filter) {
            $lines[] = sprintf('    ->filter(%s, %s%s)', $e($filter->name), $e($filter->type->value), $filter->column !== null ? ', ' . $e($filter->column) : '');
        }
        if ($index->sync !== SyncMode::Queue) {
            $lines[] = sprintf('    ->sync(%s)', $e($index->sync->value));
        }
        if ($index->triggerLevel !== TriggerLevel::Statement) {
            $lines[] = sprintf('    ->triggerLevel(%s)', $e($index->triggerLevel->value));
        }
        $lines[] = sprintf('    ->language(%s%s)', $e($index->text->language), $index->text->unaccent ? '' : ', false');
        if ($index->boostColumn !== null) {
            $lines[] = sprintf('    ->boostBy(%s)', $e($index->boostColumn));
        }
        if ($index->recencyColumn !== null) {
            $lines[] = sprintf('    ->recencyBy(%s)', $e($index->recencyColumn));
        }
        if ($index->tenant !== null) {
            $lines[] = sprintf('    ->tenant(%s)', $e($index->tenant));
        }
        $defaults = (new RankingProfile())->toArray();
        foreach ($index->profiles as $name => $profile) {
            $changed = array_filter($profile->toArray(), static fn(mixed $v, string $k): bool => $k !== 'label_weights' && $v !== $defaults[$k], ARRAY_FILTER_USE_BOTH);
            if ($name === 'default' && $changed === []) {
                continue;
            }
            $args = [];
            foreach ($changed as $key => $value) {
                $args[] = sprintf('%s: %s', lcfirst(str_replace('_', '', ucwords($key, '_'))), $e($value));
            }
            $lines[] = sprintf('    ->profile(%s, new RankingProfile(%s))', $e($name), implode(', ', $args));
        }
        $lines[] = '    ->build();';

        return "use Fuzzphony\\Core\\Definition\\IndexDefinition;\nuse Fuzzphony\\Core\\Ranking\\RankingProfile;\n\n" . implode("\n", $lines) . "\n";
    }

    private function variable(string $name): string
    {
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }
}
