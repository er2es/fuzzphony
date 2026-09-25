<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Support\Identifier;

/**
 * Turns a table profile into a ready-to-use index definition, explaining every choice.
 * Heuristics only suggest; the developer confirms (CLI) or edits (web wizard) the result.
 */
final class DefinitionSuggester
{
    private const string TITLE = '/^(name|title|label|subject|headline|nev|megnevezes|cim|termeknev|product_name|display_name|full_name)$/i';
    private const string CODE = '/(^|_)(sku|code|ean|gtin|isbn|mpn|part_number|cikkszam|azonosito|reference)$/i';
    private const string LONG = '/(description|body|content|text|summary|leiras|tartalom|notes?|details)$/i';
    private const string SENSITIVE = '/(password|passwd|hash|token|secret|salt|api_key|apikey|iban|card|ssn|taj|adoszam)/i';
    private const string BOOST = '/^(popularity|views|view_count|sales|sold|rating|score|rank|priority|likes|downloads|nepszeruseg)$/i';
    private const array RECENCY = ['published_at', 'publish_date', 'created_at', 'created', 'updated_at', 'modified_at', 'updated', 'date'];
    private const string SKIP = '/^(slug|uuid|guid|version|lock_version|deleted_at|password_.*)$/i';
    private const int MAX_JOINS = 3;
    private const string UNSAFE_NAME = 'name is not a plain identifier ([A-Za-z_][A-Za-z0-9_]*), so it is never put into generated SQL';

    /** @var list<Decision> */
    private array $decisions = [];

    public function suggest(TableProfile $table, ?string $indexName = null, string $language = 'english'): Suggestion
    {
        $this->decisions = [];
        $notes = $table->notes;

        if (!Identifier::isTable($table->table)) {
            return new Suggestion(null, [], [...$notes, sprintf('Table "%s" %s.', $table->table, self::UNSAFE_NAME)]);
        }
        if ($table->primaryKey !== null && !Identifier::isColumn($table->primaryKey)) {
            return new Suggestion(null, [], [...$notes, sprintf('Primary key "%s" %s.', $table->primaryKey, self::UNSAFE_NAME)]);
        }
        if ($table->primaryKey === null) {
            return new Suggestion(null, [], [...$notes, sprintf('Table "%s" has no single-column primary key; Fuzzphony needs one stable id per document.', $table->table)]);
        }
        $pk = $table->column($table->primaryKey);
        $name = $indexName ?? $this->indexName($table->table);
        $fkColumns = array_map(static fn(ForeignKey $fk): string => $fk->column, $table->foreignKeys);

        $fields = [];      // [column expression alias, weight, fuzzy, select sql]
        $filters = [];     // [name, FilterType, select sql]
        $boost = null;
        $recency = null;
        $hasTitle = false;

        foreach ($table->columns as $column) {
            $c = $column->name;
            if ($c === $table->primaryKey) {
                $this->decide($c, 'id', sprintf('primary key (%s)', $column->sqlType));
                continue;
            }
            if (!Identifier::isColumn($c)) {
                $this->decide($c, 'skip', self::UNSAFE_NAME);
                continue;
            }
            if (preg_match(self::SENSITIVE, $c) === 1) {
                $this->decide($c, 'skip', 'looks sensitive; never indexed automatically');
                continue;
            }
            if (in_array($c, $fkColumns, true)) {
                $filters[] = [$this->ident($c), FilterType::Int, 't.' . Identifier::quote($c), $c];
                $this->decide($c, 'filter', 'foreign key: filter by relation, label searched via join');
                continue;
            }
            if (preg_match(self::SKIP, $c) === 1) {
                $this->decide($c, 'skip', 'technical column');
                continue;
            }

            switch ($column->kind) {
                case ColumnKind::Text:
                    [$weight, $fuzzy, $reason, $asFilter] = $this->classifyText($column, $table->estimatedRows, $hasTitle);
                    if ($asFilter) {
                        $filters[] = [$this->ident($c), FilterType::String, 't.' . Identifier::quote($c), $c];
                        $this->decide($c, 'filter', $reason);
                        break;
                    }
                    $hasTitle = $hasTitle || $weight === 'A';
                    $fields[] = [$this->ident($c), $weight, $fuzzy, 't.' . Identifier::quote($c), $c];
                    $this->decide($c, sprintf('field %s%s', $weight, $fuzzy ? ', fuzzy' : ''), $reason);
                    break;

                case ColumnKind::Int:
                case ColumnKind::Float:
                    if ($boost === null && preg_match(self::BOOST, $c) === 1) {
                        $boost = $column;
                        $this->decide($c, 'boost', 'popularity-like number: used by the "popular" ranking profile');
                        break;
                    }
                    $filters[] = [$this->ident($c), $column->kind === ColumnKind::Int ? FilterType::Int : FilterType::Float, 't.' . Identifier::quote($c), $c];
                    $this->decide($c, 'filter', 'number: range filters (price, stock, ...)');
                    break;

                case ColumnKind::Bool:
                    $filters[] = [$this->ident($c), FilterType::Bool, 't.' . Identifier::quote($c), $c];
                    $this->decide($c, 'filter', 'yes/no flag');
                    break;

                case ColumnKind::Date:
                case ColumnKind::DateTime:
                    $filters[] = [$this->ident($c), $column->kind === ColumnKind::Date ? FilterType::Date : FilterType::DateTime, 't.' . Identifier::quote($c), $c];
                    $this->decide($c, 'filter', 'date: range filters');
                    break;

                default:
                    $this->decide($c, 'skip', sprintf('type %s is not searchable', $column->sqlType));
            }
        }

        // Recency: the most meaningful timestamp, in priority order.
        foreach (self::RECENCY as $candidate) {
            $column = $table->column($candidate);
            if ($column !== null && ($column->kind === ColumnKind::DateTime || $column->kind === ColumnKind::Date)) {
                $recency = $column;
                $this->decide($candidate, 'recency', 'newest first in the "popular" ranking profile');
                break;
            }
        }

        // Joins: label column of each referenced table (brand.name) becomes a B field.
        $joins = [];
        $watches = [];
        foreach (array_slice($table->foreignKeys, 0, self::MAX_JOINS) as $i => $fk) {
            $unsafe = match (true) {
                !Identifier::isColumn($fk->column) => $fk->column,
                !Identifier::isTable($fk->referencedTable) => $fk->referencedTable,
                !Identifier::isColumn($fk->referencedColumn) => $fk->referencedColumn,
                default => null,
            };
            if ($unsafe !== null) {
                $this->decide($fk->column, 'skip', sprintf('not joined: "%s" is not a plain identifier, so it is never put into generated SQL', $unsafe));
                continue;
            }
            $label = $fk->referenced !== null ? $this->labelColumn($fk->referenced) : null;
            if ($label === null) {
                continue;
            }
            $alias = 'j' . $i;
            $fieldName = $this->relationName($fk->column, $fk->referencedTable);
            $joins[] = sprintf(
                'LEFT JOIN %s %s ON %s.%s = t.%s',
                Identifier::quote($fk->referencedTable),
                $alias,
                $alias,
                Identifier::quote($fk->referencedColumn),
                Identifier::quote($fk->column),
            );
            $fields[] = [$fieldName, $hasTitle ? 'B' : 'A', true, sprintf('%s.%s', $alias, Identifier::quote($label)), $fieldName];
            $watches[] = [$fk->referencedTable, sprintf('SELECT %s FROM %s WHERE %s = :id', Identifier::quote($table->primaryKey), Identifier::quote($table->table), Identifier::quote($fk->column)), $fk->referencedColumn];
            $this->decide($fk->column, sprintf('join %s.%s -> field %s', $fk->referencedTable, $label, $fieldName), 'related label (brand, category, author, ...) is searchable; changes in that table are watched');
        }

        if ($fields === []) {
            return new Suggestion(null, $this->decisions, [...$notes, 'No text column looks searchable. Pick fields manually.']);
        }

        $builder = IndexDefinition::builder($name)->idType($this->idType($pk))->language($language);
        if ($joins === []) {
            // Plain table: keep the real column names, map them to normalised field names.
            $builder->fromTable($table->table, $table->primaryKey);
            foreach ($fields as [$field, $weight, $fuzzy, , $column]) {
                $builder->field($field, $weight, $fuzzy, column: $column !== $field ? $column : null);
            }
            foreach ($filters as [$filter, $type, , $column]) {
                $builder->filter($filter, $type, $column !== $filter ? $column : null);
            }
        } else {
            // Joins: one SELECT with stable aliases; the main table and every joined table are watched.
            $select = [sprintf('t.%s', Identifier::quote($table->primaryKey))];
            foreach ($fields as [$alias, , , $expression]) {
                $select[] = $expression === 't.' . Identifier::quote($alias) ? $expression : sprintf('%s AS %s', $expression, Identifier::quote($alias));
            }
            foreach ($filters as [$alias, , $expression]) {
                $select[] = $expression === 't.' . Identifier::quote($alias) ? $expression : sprintf('%s AS %s', $expression, Identifier::quote($alias));
            }
            foreach (array_filter([$boost, $recency]) as $extra) {
                $select[] = 't.' . Identifier::quote($extra->name);
            }
            $builder->fromQuery(sprintf("SELECT %s\nFROM %s t\n%s", implode(', ', array_unique($select)), Identifier::quote($table->table), implode("\n", $joins)), $table->primaryKey);
            $builder->watch($table->table, 'SELECT :id', $table->primaryKey);
            foreach ($watches as [$watchTable, $ids, $key]) {
                $builder->watch($watchTable, $ids, $key);
            }
            foreach ($fields as [$field, $weight, $fuzzy]) {
                $builder->field($field, $weight, $fuzzy);
            }
            foreach ($filters as [$filter, $type]) {
                $builder->filter($filter, $type);
            }
        }

        if ($boost !== null || $recency !== null) {
            if ($boost !== null) {
                $builder->boostBy($boost->name);
            }
            if ($recency !== null) {
                $builder->recencyBy($recency->name);
            }
            $builder->profile('popular', new RankingProfile(
                boost: $boost !== null ? $this->boostWeight($boost, $notes) : 0.0,
                recency: $recency !== null ? 0.3 : 0.0,
            ));
        }
        if ($table->estimatedRows > 5_000_000) {
            $notes[] = 'Large table: run the first reindex off-peak; queue sync keeps writes cheap afterwards.';
        }

        try {
            $definition = $builder->build();
        } catch (InvalidDefinition $e) {
            return new Suggestion(null, $this->decisions, [...$notes, ...$e->violations]);
        }

        return new Suggestion($definition, $this->decisions, $notes);
    }

    /** @return array{0: string, 1: bool, 2: string, 3: bool} weight, fuzzy, reason, use as filter instead */
    private function classifyText(ColumnProfile $column, int $rows, bool $hasTitle): array
    {
        $c = $column->name;
        $length = $column->averageLength;

        if (preg_match(self::TITLE, $c) === 1 && !$hasTitle) {
            return ['A', true, 'looks like the title: most important, typo tolerant', false];
        }
        if (preg_match(self::CODE, $c) === 1) {
            return ['B', false, 'identifier / code: exact and prefix matches matter, typos do not', false];
        }
        if ($column->distinct !== null && $column->distinct <= 50 && $rows >= 200 && ($length === null || $length <= 30)) {
            return ['', false, sprintf('only ~%d distinct values: better as a filter (status, type, ...)', (int) $column->distinct), true];
        }
        if (preg_match(self::LONG, $c) === 1 || ($length !== null && $length > 200)) {
            return ['D', false, 'long text: searchable, but weighted lowest', false];
        }
        if ($length !== null && $length <= 60) {
            return ['B', false, 'short text', false];
        }

        return ['C', false, 'medium-length text', false];
    }

    private function labelColumn(TableProfile $table): ?string
    {
        $text = array_values(array_filter($table->columns, static fn(ColumnProfile $c): bool => $c->kind === ColumnKind::Text && Identifier::isColumn($c->name)));
        foreach ($text as $column) {
            if (preg_match(self::TITLE, $column->name) === 1) {
                return $column->name;
            }
        }

        return count($text) === 1 ? $text[0]->name : null;
    }

    /** @param list<string> $notes */
    private function boostWeight(ColumnProfile $boost, array &$notes): float
    {
        if ($boost->approximateMax !== null && $boost->approximateMax > 0.0) {
            // the most popular document gets a bonus of ~0.3, comparable to a good text match
            $weight = 0.3 / $boost->approximateMax;
            $magnitude = 10 ** floor(log10($weight));

            return round($weight / $magnitude) * $magnitude;
        }
        $notes[] = sprintf('Could not estimate the range of "%s"; boost weight 0.01 is a guess, tune it in the playground.', $boost->name);

        return 0.01;
    }

    private function idType(?ColumnProfile $pk): IdType
    {
        return match ($pk?->kind) {
            ColumnKind::Int => IdType::Int,
            ColumnKind::Uuid => IdType::Uuid,
            default => IdType::String,
        };
    }

    private function relationName(string $fkColumn, string $referencedTable): string
    {
        $base = (string) preg_replace('/_id$/', '', $fkColumn);
        $base = $base === $fkColumn ? (string) preg_replace('/^.*\./', '', $referencedTable) : $base;

        return strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', $base));
    }

    private function indexName(string $table): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) preg_replace('/^.*\./', '', $table)));

        return str_ends_with($base, 's') ? $base : $base . 's';
    }

    private function ident(string $column): string
    {
        $name = strtolower(trim((string) preg_replace('/[^a-z0-9_]+/i', '_', Identifier::snake($column)), '_'));

        return preg_match('/^[a-z_]/', $name) === 1 ? $name : 'c_' . $name;
    }

    private function decide(string $column, string $role, string $reason): void
    {
        $this->decisions[] = new Decision($column, $role, $reason);
    }
}
