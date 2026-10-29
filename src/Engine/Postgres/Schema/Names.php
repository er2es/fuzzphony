<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal Every database object name Fuzzphony creates, in one place. Methods ending in "Name"
 * return the bare name (catalog lookups, derived index and trigger names); the others return SQL,
 * quoted and qualified with Fuzzphony's schema, so no statement depends on the caller's search_path.
 * Index and trigger names are never qualified: an index lives in its table's schema, a trigger on
 * its table.
 */
final readonly class Names
{
    /** PostgreSQL's identifier limit (NAMEDATALEN - 1). */
    public const int MAX_IDENTIFIER_BYTES = 63;
    private const string PREFIX = 'fuzzphony_';

    public function __construct(
        /** Schema of the pg_trgm and unaccent extensions. */
        public string $extensionSchema = 'public',
        /** Schema of Fuzzphony's own tables, functions and text search configurations. */
        public string $schema = 'public',
    ) {
        if (!Identifier::isColumn($extensionSchema)) {
            throw new InvalidConfiguration(sprintf('Invalid extension schema "%s".', $extensionSchema));
        }
        if (!Identifier::isColumn($schema)) {
            throw new InvalidConfiguration(sprintf('Invalid schema "%s": use a plain identifier such as "fuzzphony".', $schema));
        }
        if (str_starts_with($schema, 'pg_')) {
            throw new InvalidConfiguration(sprintf('Invalid schema "%s": names starting with "pg_" are reserved by PostgreSQL.', $schema));
        }
    }

    /** Keeps a generated name within PostgreSQL's limit while staying unique. */
    public static function limit(string $name, int $max = self::MAX_IDENTIFIER_BYTES): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 9) . '_' . hash('crc32b', $name);
    }

    public function extension(): string
    {
        return Sql::ident($this->extensionSchema);
    }

    public function quotedSchema(): string
    {
        return Sql::ident($this->schema);
    }

    public function sidecarName(IndexDefinition $index): string
    {
        return self::PREFIX . $index->name;
    }

    public function sidecar(IndexDefinition $index): string
    {
        return $this->qualify($this->sidecarName($index));
    }

    public function queue(): string
    {
        return $this->qualify(self::PREFIX . 'queue');
    }

    public function queueOrderIndex(): string
    {
        return self::PREFIX . 'queue_order';
    }

    public function metaName(): string
    {
        return self::PREFIX . 'meta';
    }

    /** One row per index (plus "*" for the shared objects): the layout and definition it was built from. */
    public function meta(): string
    {
        return $this->qualify($this->metaName());
    }

    /** The opt-in search log: one row per recorded search (SearchLogStore). */
    public function searchLog(): string
    {
        return $this->qualify(self::PREFIX . 'search_log');
    }

    /** An index of the search log table ("index_at", "index_norm"); bare, like the other index names. */
    public function searchLogIndexName(string $suffix): string
    {
        return self::limit(self::PREFIX . 'search_log_' . $suffix);
    }

    public function normFunction(): string
    {
        return $this->qualify(self::PREFIX . 'norm');
    }

    public function refreshFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'refresh_' . $index->name);
    }

    public function refreshFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->refreshFunctionName($index));
    }

    public function syncFunctionName(IndexDefinition $index, Watch $watch): string
    {
        return self::limit(self::PREFIX . 'sync_' . $index->name . '__' . str_replace('.', '_', $watch->table));
    }

    public function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        return $this->qualify($this->syncFunctionName($index, $watch));
    }

    /** The table a full reindex builds next to the live one, swapped in when it is complete. */
    public function shadowName(IndexDefinition $index): string
    {
        return self::limit($this->sidecarName($index) . '__next');
    }

    public function shadow(IndexDefinition $index): string
    {
        return $this->qualify($this->shadowName($index));
    }

    /** The words of a fuzzy index and in how many documents each occurs: where "did you mean" (and later suggest()) read. */
    public function vocabularyName(IndexDefinition $index): string
    {
        return self::limit($this->sidecarName($index) . '__vocab');
    }

    public function vocabulary(IndexDefinition $index): string
    {
        return $this->qualify($this->vocabularyName($index));
    }

    /** Its trigram index, which finds the words close to a misspelled one. */
    public function vocabularyIndexName(IndexDefinition $index): string
    {
        return self::limit($this->vocabularyName($index) . '_trgm');
    }

    /** Its prefix index, which completes the beginning of a word (suggest()). */
    public function vocabularyPrefixIndexName(IndexDefinition $index): string
    {
        return self::limit($this->vocabularyName($index) . '_prefix');
    }

    /** The ids of the live documents that changed while a full reindex runs. */
    public function changesName(IndexDefinition $index): string
    {
        return self::limit($this->sidecarName($index) . '__changes');
    }

    public function changes(IndexDefinition $index): string
    {
        return $this->qualify($this->changesName($index));
    }

    public function shadowRefreshFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'refresh_' . $index->name . '__next');
    }

    public function shadowRefreshFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->shadowRefreshFunctionName($index));
    }

    /** The trigger function (and the trigger on the live table) that fills the change log. */
    public function trackFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'track_' . $index->name);
    }

    public function trackFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->trackFunctionName($index));
    }

    /** The name an index (or the primary key) of the rebuild table has until the swap gives it $liveName. */
    public function shadowIndexName(string $liveName): string
    {
        return self::limit($liveName . '__next');
    }

    /** An index of one of Fuzzphony's tables, qualified: ALTER INDEX needs the schema. */
    public function index(string $name): string
    {
        return $this->qualify($name);
    }

    /** The advisory lock key of an index's full rebuild (pg_advisory_lock(hashtext(key))). */
    public function rebuildLockKey(IndexDefinition $index): string
    {
        return 'fuzzphony:' . $this->schema . '.' . $index->name;
    }

    /** "" = the row-level trigger (named like its function); "_ins", "_upd", "_del", "_trn" = the others. */
    public function triggerName(IndexDefinition $index, Watch $watch, string $suffix = ''): string
    {
        $function = $this->syncFunctionName($index, $watch);

        return $suffix === '' ? $function : self::limit($function . $suffix);
    }

    public function indexName(IndexDefinition $index, string $suffix): string
    {
        if (preg_match('/^' . OpenClass::NAME_TAIL . '$/', $suffix) === 1 && strlen($this->sidecarName($index) . '_' . $suffix) > self::MAX_IDENTIFIER_BYTES) {
            // an open-class index keeps its `_open_..._<hash>` tail whatever the length (the apply, the doctor and the restart reminder
            // find it by that tail): the sidecar part is shortened instead, with its own hash so that two long names stay apart
            $sidecar = $this->sidecarName($index);

            return substr($sidecar, 0, self::MAX_IDENTIFIER_BYTES - strlen($suffix) - 10) . '_' . hash('crc32b', $sidecar) . '_' . $suffix;
        }

        return self::limit($this->sidecarName($index) . '_' . $suffix);
    }

    /** The sidecar column with one field's own weighted tsvector (layout 2): a field-scoped word is checked against it. */
    public function fieldVectorName(string $field): string
    {
        return self::limit('t_' . $field);
    }

    public function fieldVector(string $field): string
    {
        return Sql::ident($this->fieldVectorName($field));
    }

    /**
     * The fields' own vectors joined from each field after the first to the last, in the index's order (`s.t_b || s.t_c`,
     * `s.t_c`): `tsv` is all of them joined and its positions are capped at 16 383, so after a field of more than about 16 000
     * words every later field is at one position there; each of these starts at a field that follows it and is unaffected.
     * Every run of fields that `tsv` reads in order is one of these, or `tsv` itself (from the first field). $alias is the
     * sidecar table's alias in the statement.
     *
     * @return list<string>
     */
    public function laterVectors(IndexDefinition $index, string $alias = 's'): array
    {
        $vectors = array_map(fn(string $name): string => $alias . '.' . $this->fieldVector($name), $index->fieldNames());
        $joined = [];
        for ($i = 1, $n = count($vectors); $i < $n; ++$i) {
            $joined[] = implode(' || ', array_slice($vectors, $i));
        }

        return $joined;
    }

    /** The sidecar column with one fuzzy field's normalised text (layout 2): a field-scoped typo is checked against it. */
    public function fieldFuzzyName(string $field): string
    {
        return self::limit('z_' . $field);
    }

    public function fieldFuzzy(string $field): string
    {
        return Sql::ident($this->fieldFuzzyName($field));
    }

    /** The configuration an index uses: Fuzzphony's accent-folding copy, or the built-in one. */
    public function textConfigName(TextConfig $config): string
    {
        return $config->unaccent ? self::PREFIX . $config->language : $config->language;
    }

    public function textConfig(TextConfig $config): string
    {
        return $config->unaccent ? $this->qualify($this->textConfigName($config)) : Sql::ident($config->language);
    }

    /** The configuration as a SQL value, for to_tsvector() / to_tsquery() / ts_headline(). */
    public function regconfig(TextConfig $config): string
    {
        return Sql::string($this->textConfig($config)) . '::regconfig';
    }

    /** The dictionary the accent-folding configuration consults for stop words, before unaccent. */
    public function stopDictionaryName(TextConfig $config): string
    {
        return self::PREFIX . $config->language . '_stop';
    }

    public function stopDictionary(TextConfig $config): string
    {
        return $this->qualify($this->stopDictionaryName($config));
    }

    private function qualify(string $name): string
    {
        return Sql::ident($this->schema . '.' . $name);
    }

    /**
     * The vector the text rank and the highlighter judge the strict query on: the whole document (`s.tsv`), or for a restricted
     * viewer only the vectors of the fields it may see; a hidden field never takes part.
     *
     * @param list<string>|null $allowed
     */
    public function visibleVector(IndexDefinition $index, ?array $allowed): string
    {
        if ($allowed === null) {
            return 's.tsv';
        }
        $parts = array_map(
            fn(string $name): string => 's.' . $this->fieldVector($name),
            array_values(array_filter($index->fieldNames(), static fn(string $name): bool => in_array($name, $allowed, true))),
        );

        return match (count($parts)) {
            0 => "''::tsvector",
            1 => $parts[0],
            default => '(' . implode(' || ', $parts) . ')',
        };
    }
}
