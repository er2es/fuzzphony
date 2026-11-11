<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Closest;
use Fuzzphony\Core\Support\Identifier;

/**
 * Where the several values of a field or filter come from: one row per value in a child table (a customer with several addresses).
 *
 *   new ManyValues(from: 'address', key: 'customer_id', column: 'city', limit: 50)
 *
 * The first `limit` values of a document are indexed, ordered by the value, then by the child's primary key (`id`); the doctor names the
 * documents that have more.
 */
final readonly class ManyValues
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 10000;

    public const array KEYS = ['from', 'key', 'column', 'limit', 'id', 'rank'];

    public function __construct(
        /** The child table (optionally schema-qualified). */
        public string $from,
        /** The child column that holds the owning document's id. */
        public string $key,
        /** The child column that holds the value. */
        public string $column,
        /** At most this many values per document are indexed. */
        public int $limit = self::DEFAULT_LIMIT,
        /** The child's primary key column: the tie-breaker of the value order. */
        public string $id = 'id',
        /** Whole: the values are ranked as one text (the default); Best: the field ranks through its best single value (fields only). */
        public ManyRank $rank = ManyRank::Whole,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidDefinition
     */
    public static function fromArray(string $index, string $what, array $config): self
    {
        $problems = [];
        $unknown = array_values(array_diff(array_map('strval', array_keys($config)), self::KEYS));
        foreach ($unknown as $key) {
            $closest = Closest::of($key, self::KEYS);
            $problems[] = sprintf('%s: "many" has an unknown option "%s". Allowed: %s.%s', $what, $key, implode(', ', self::KEYS), $closest === null ? '' : sprintf(' Did you mean "%s"?', $closest));
        }
        foreach (['from', 'key', 'column'] as $required) {
            if (!is_string($config[$required] ?? null) || $config[$required] === '') {
                $problems[] = sprintf('%s: "many" needs "%s" (a non-empty string), e.g. many: { from: address, key: customer_id, column: city }.', $what, $required);
            }
        }
        if (array_key_exists('id', $config) && (!is_string($config['id']) || $config['id'] === '')) {
            $problems[] = sprintf('%s: many "id" must be the child table\'s primary key column (a non-empty string).', $what);
        }
        if (array_key_exists('limit', $config) && !is_int($config['limit'])) {
            $problems[] = sprintf('%s: many "limit" must be an integer between 1 and %d.', $what, self::MAX_LIMIT);
        }
        $rank = ManyRank::Whole;
        if (array_key_exists('rank', $config)) {
            $parsed = $config['rank'] instanceof ManyRank ? $config['rank'] : (is_string($config['rank']) ? ManyRank::tryFrom($config['rank']) : null);
            if ($parsed === null) {
                $problems[] = self::rankProblem($what, $config['rank']);
            } else {
                $rank = $parsed;
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition($index, $problems);
        }

        return new self(
            is_string($config['from'] ?? null) ? $config['from'] : '',
            is_string($config['key'] ?? null) ? $config['key'] : '',
            is_string($config['column'] ?? null) ? $config['column'] : '',
            is_int($config['limit'] ?? null) ? $config['limit'] : self::DEFAULT_LIMIT,
            is_string($config['id'] ?? null) ? $config['id'] : 'id',
            $rank,
        );
    }

    /** The message for a "rank" that is neither "whole" nor "best" (also the definition loader's). */
    public static function rankProblem(string $what, mixed $got): string
    {
        $closest = is_string($got) ? Closest::of($got, array_column(ManyRank::cases(), 'value')) : null;

        return sprintf(
            '%s: many "rank" must be "whole" (the default: the values ranked as one text) or "best" (the best single value), got %s.%s',
            $what,
            is_scalar($got) ? var_export($got, true) : get_debug_type($got),
            $closest === null ? '' : sprintf(' Did you mean "%s"?', $closest),
        );
    }

    /** @return list<string> */
    public function violations(string $what): array
    {
        $v = [];
        if (!Identifier::isTable($this->from)) {
            $v[] = sprintf('%s: many "from" table "%s" is not a valid identifier (use "table" or "schema.table").', $what, $this->from);
        }
        foreach (['key' => $this->key, 'column' => $this->column, 'id' => $this->id] as $option => $column) {
            if (!Identifier::isColumn($column)) {
                $v[] = sprintf('%s: many "%s" column "%s" is not a valid column name.', $what, $option, $column);
            }
        }
        if ($this->limit < 1 || $this->limit > self::MAX_LIMIT) {
            $v[] = sprintf('%s: many "limit" must be between 1 and %d, got %d.', $what, self::MAX_LIMIT, $this->limit);
        }

        return $v;
    }

    /**
     * The definition fingerprint: the rank is part of it only when it is Best, so every 0.11 definition keeps its hash.
     *
     * @return array{string, string, string, int, string}|array{string, string, string, int, string, string}
     */
    public function fingerprint(): array
    {
        return $this->rank === ManyRank::Best ? [...$this->documentsFingerprint(), 'best'] : $this->documentsFingerprint();
    }

    /**
     * What the stored documents depend on: the rank only changes how a search reads the values table, so it is not in here (turning it on
     * needs `schema --apply`, never a reindex).
     *
     * @return array{string, string, string, int, string}
     */
    public function documentsFingerprint(): array
    {
        return [$this->from, $this->key, $this->column, $this->limit, $this->id];
    }

    /** @return array{from: string, key: string, column: string, limit?: int, id?: string, rank?: string} */
    public function toArray(): array
    {
        $array = ['from' => $this->from, 'key' => $this->key, 'column' => $this->column];
        if ($this->limit !== self::DEFAULT_LIMIT) {
            $array['limit'] = $this->limit;
        }
        if ($this->id !== 'id') {
            $array['id'] = $this->id;
        }
        if ($this->rank === ManyRank::Best) {
            $array['rank'] = $this->rank->value;
        }

        return $array;
    }
}
