<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard\Export;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * Definition -> the array shape read by ArrayDefinitionLoader (round-trips).
 * Defaults are omitted so the exported configuration stays short.
 */
final class ArrayExporter
{
    /** @return array<string, mixed> */
    public function export(IndexDefinition $index): array
    {
        $out = [];
        $source = $index->source;
        $out['source'] = array_filter([
            'table' => $source->table,
            'query' => $source->query,
            'id' => $source->idColumn !== 'id' ? $source->idColumn : null,
        ], static fn(mixed $v): bool => $v !== null);
        if ($index->idType->value !== 'int') {
            $out['id_type'] = $index->idType->value;
        }

        foreach ($index->fields as $field) {
            $options = array_filter([
                'weight' => $field->weight->value,
                'fuzzy' => $field->fuzzy ? true : null,
                'highlight' => $field->highlight ? null : false,
                'column' => $field->column,
            ], static fn(mixed $v): bool => $v !== null);
            $out['fields'][$field->name] = count($options) === 1 ? $field->weight->value : $options;
        }
        foreach ($index->filters as $filter) {
            $out['filters'][$filter->name] = $filter->column === null ? $filter->type->value : ['type' => $filter->type->value, 'column' => $filter->column];
        }
        foreach ($index->watches as $watch) {
            $out['watch'][$watch->table] = $watch->keyColumn === 'id' ? $watch->affectedIds : ['ids' => $watch->affectedIds, 'key' => $watch->keyColumn];
        }

        if ($index->sync !== SyncMode::Queue) {
            $out['sync'] = $index->sync->value;
        }
        if ($index->triggerLevel !== TriggerLevel::Statement) {
            $out['trigger_level'] = $index->triggerLevel->value;
        }
        $out['language'] = $index->text->language;
        if (!$index->text->unaccent) {
            $out['unaccent'] = false;
        }
        if ($index->boostColumn !== null) {
            $out['boost'] = $index->boostColumn;
        }
        if ($index->recencyColumn !== null) {
            $out['recency'] = $index->recencyColumn;
        }

        $defaults = (new RankingProfile())->toArray();
        foreach ($index->profiles as $name => $profile) {
            $diff = array_filter($profile->toArray(), static fn(mixed $v, string $k): bool => $v !== $defaults[$k], ARRAY_FILTER_USE_BOTH);
            if ($name !== 'default' || $diff !== []) {
                $out['profiles'][$name] = $diff;
            }
        }

        $thresholds = $this->thresholds($index->thresholds);
        if ($thresholds !== []) {
            $out['thresholds'] = $thresholds;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function thresholds(Thresholds $t): array
    {
        $d = new Thresholds();
        $out = [];
        foreach (['min_score' => 'minScore', 'fuzzy_similarity' => 'fuzzySimilarity', 'fuzzy_min_length' => 'fuzzyMinLength', 'fallback_below' => 'fallbackBelow', 'candidate_limit' => 'candidateLimit', 'max_query_length' => 'maxQueryLength', 'max_terms' => 'maxTerms'] as $key => $property) {
            if ($t->{$property} !== $d->{$property}) {
                $out[$key] = $t->{$property};
            }
        }
        if ($t->fuzzyMode !== $d->fuzzyMode) {
            $out['fuzzy_mode'] = $t->fuzzyMode->value;
        }

        return $out;
    }
}
