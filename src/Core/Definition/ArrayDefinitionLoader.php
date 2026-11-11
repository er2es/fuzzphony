<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Support\Closest;

/**
 * @internal Builds or overrides definitions from plain arrays (YAML config). Shape:
 *
 *   products:
 *     source: { table: product, id: id }            # or { query: "SELECT ...", id: id } or { query_file: sql/catalog.sql }
 *     id_type: int
 *     # or, instead of source / id_type / watch / class: several sources sharing the fields below
 *     sources: { product: { query: "SELECT ...", watch: { ... }, entity: App\Product, id_type: int }, article: { table: article, id_type: uuid } }
 *     fields: { name: { weight: A, fuzzy: true }, description: C, salary: { weight: B, granted: ROLE_HR } }
 *     filters: { price: int, in_stock: bool }
 *     returnable: [name, slug, price]   # source columns SearchBuilder::with() may return (per source: sources.<name>.returnable)
 *     watch: { brand: { ids: "SELECT id FROM product WHERE brand_id = :id", columns: [name] } }
 *     sync: queue
 *     trigger_level: statement   # or row
 *     language: english
 *     unaccent: true
 *     boost: popularity
 *     recency: published_at
 *     tenant: account_id
 *     synonyms: [[tv, television], 'laptop => notebook']
 *     log: true                  # false keeps the index out of the search log
 *     profiles: { default: { text: 1, fuzzy: 0.5 } }
 *     thresholds: { relevance: { min_score: 0.05 }, typo: { mode: fallback } }
 *
 * The shape is checked as a whole before anything is built: unknown keys at every level, a list where a map
 * belongs, a string where a boolean belongs (the string "false" is not false) are InvalidDefinition problems.
 */
final class ArrayDefinitionLoader
{
    private const array KEYS = ['source', 'sources', 'id_type', 'fields', 'filters', 'watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'class', 'trigger_level', 'tenant', 'synonyms', 'log', 'returnable'];

    /** What an override can change on an index its attributes define; the other keys describe the index itself. */
    private const array OVERRIDABLE = ['watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'trigger_level', 'tenant', 'synonyms', 'log', 'returnable'];

    private const array SOURCE_KEYS = ['table', 'query', 'query_file', 'id'];
    private const array SOURCES_KEYS = ['table', 'query', 'query_file', 'id', 'watch', 'entity', 'id_type', 'returnable'];
    private const array FIELD_KEYS = ['weight', 'fuzzy', 'highlight', 'column', 'granted', 'code', 'many'];
    private const array FILTER_KEYS = ['type', 'column', 'many'];
    private const array WATCH_KEYS = ['ids', 'key', 'columns'];
    private const array STRING_KEYS = ['id_type', 'sync', 'trigger_level', 'language', 'boost', 'recency', 'tenant'];
    private const array BOOL_KEYS = ['unaccent', 'log'];

    /** @param ?string $baseDir what a relative `source.query_file` starts at (the project root); null: the current working directory */
    public function __construct(private readonly ?string $baseDir = null) {}

    /** @param array<string, mixed> $config */
    public function load(string $name, array $config): IndexDefinition
    {
        $this->assertShape($name, $config, false);
        $builder = IndexDefinition::builder($name);

        if (isset($config['sources'])) {
            $this->sources($builder, $name, self::map($config['sources']));
        } else {
            /** @var array{table?: string, query?: string, query_file?: string, id?: string} $source */
            $source = $config['source'] ?? ['table' => $name];
            match (true) {
                isset($source['query']) => $builder->fromQuery($source['query'], $source['id'] ?? 'id'),
                isset($source['query_file']) => $builder->fromQueryFile($source['query_file'], $this->baseDir, $source['id'] ?? 'id'),
                default => $builder->fromTable($source['table'] ?? $name, $source['id'] ?? 'id'),
            };
        }

        foreach (self::map($config['fields'] ?? []) as $field => $options) {
            $options = is_string($options) ? ['weight' => $options] : self::map($options);
            $builder->field($field, self::str($options['weight'] ?? null, 'B'), ($options['fuzzy'] ?? false) === true, ($options['highlight'] ?? true) === true, isset($options['column']) ? self::str($options['column'], '') : null, isset($options['granted']) ? self::str($options['granted'], '') : null, ($options['code'] ?? false) === true, isset($options['many']) && is_array($options['many']) ? $options['many'] : null);
        }
        foreach (self::map($config['filters'] ?? []) as $filter => $options) {
            $options = is_string($options) ? ['type' => $options] : self::map($options);
            $builder->filter($filter, self::str($options['type'] ?? null, ''), isset($options['column']) ? self::str($options['column'], '') : null, isset($options['many']) && is_array($options['many']) ? $options['many'] : null);
        }
        foreach (self::map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : self::map($options);
            $builder->watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($name, $options['columns'] ?? null));
        }

        $builder->returnable(self::returnableList($config['returnable'] ?? null));
        $this->apply($builder, $config, $name);
        if (is_string($config['class'] ?? null) && class_exists($config['class'])) {
            $builder->entity($config['class']);
        }

        return $builder->build();
    }

    /** @param array<string, mixed> $sources */
    private function sources(IndexBuilder $builder, string $index, array $sources): void
    {
        foreach ($sources as $source => $options) {
            $options = self::map($options);
            $watch = [];
            foreach (self::map($options['watch'] ?? []) as $table => $entry) {
                $entry = is_string($entry) ? ['ids' => $entry] : self::map($entry);
                $watch[] = new Watch($table, self::str($entry['ids'] ?? null, 'SELECT :id'), self::str($entry['key'] ?? null, 'id'), self::stringList($index, $entry['columns'] ?? null));
            }
            $entity = isset($options['entity']) && is_string($options['entity']) ? $options['entity'] : null;
            /** @var class-string|null $entity */
            $idType = isset($options['id_type']) ? self::str($options['id_type'], '') : null;
            $id = self::str($options['id'] ?? null, 'id');
            $returnable = self::returnableList($options['returnable'] ?? null);
            $source = (string) $source;
            match (true) {
                isset($options['query']) => $builder->source($source, self::str($options['query'], ''), $entity, $watch, $idType, $id, $returnable),
                isset($options['query_file']) => $builder->source($source, QueryFile::read($index, self::str($options['query_file'], ''), $this->baseDir), $entity, $watch, $idType, $id, $returnable),
                default => $builder->sourceFromTable($source, self::str($options['table'] ?? null, $source), $entity, $watch, $idType, $id, $returnable),
            };
        }
    }

    /**
     * Applies YAML overrides on top of an attribute-based definition (attributes stay primary).
     * The keys that describe the index itself (source, id_type, fields, filters, class) are refused.
     *
     * @param array<string, mixed> $config
     */
    public function override(IndexDefinition $definition, array $config): IndexDefinition
    {
        $name = $definition->name;
        $this->assertShape($name, $config, true);
        $merged = $definition;
        if (isset($config['sync'])) {
            $merged = $merged->withSync(EnumOption::parse(SyncMode::class, self::str($config['sync'], ''), $name, 'sync mode'));
        }
        if (isset($config['trigger_level'])) {
            $merged = $merged->withTriggerLevel(EnumOption::parse(TriggerLevel::class, self::str($config['trigger_level'], ''), $name, 'trigger level'));
        }
        if (isset($config['language']) || isset($config['unaccent'])) {
            $merged = $merged->withText(new TextConfig(self::str($config['language'] ?? null, $definition->text->language), ($config['unaccent'] ?? $definition->text->unaccent) === true));
        }
        if (isset($config['boost'])) {
            $merged = $merged->withBoostColumn(self::str($config['boost'], ''));
        }
        if (isset($config['recency'])) {
            $merged = $merged->withRecencyColumn(self::str($config['recency'], ''));
        }
        if (isset($config['tenant'])) {
            $merged = $merged->withTenant(self::str($config['tenant'], ''));
        }
        if (isset($config['log'])) {
            $merged = $merged->withLog($config['log'] === true);
        }
        if (array_key_exists('synonyms', $config)) {
            $merged = $merged->withSynonyms($this->synonyms($name, $config['synonyms']));
        }
        if (isset($config['profiles'])) {
            $merged = $merged->withProfiles($this->profiles($name, $config['profiles']) + $definition->profiles);
        }
        if (isset($config['thresholds'])) {
            $merged = $merged->withThresholds($this->scoped($name, 'thresholds', static fn(): Thresholds => $definition->thresholds->with(self::map($config['thresholds']))));
        }
        if ($definition->isMultiSource() && isset($config['returnable'])) {
            throw new InvalidDefinition($name, [sprintf('"returnable" cannot be set by a YAML override on index "%s": its columns belong to its sources, declared where the index is defined.', $name)]);
        }
        if ($definition->isMultiSource() && isset($config['watch'])) {
            throw new InvalidDefinition($name, [sprintf('"watch" cannot be set by a YAML override on index "%s": its watches belong to its sources, declared where the index is defined.', $name)]);
        }
        $watches = $definition->watches;
        foreach (self::map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : self::map($options);
            $watches[] = new Watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($name, $options['columns'] ?? null));
        }
        $merged = $merged->withWatches($watches);
        if (isset($config['returnable']) && !$definition->isMultiSource()) {
            ['map' => $map] = Returnable::normalise(self::returnableList($config['returnable']));
            ['plain' => $plain, 'aliases' => $aliases] = Returnable::split($map);
            $merged = $merged->withReturnable($plain, $aliases);
        }
        DefinitionValidator::assertValid($merged);

        return $merged;
    }

    /** @param array<string, mixed> $config */
    private function apply(IndexBuilder $builder, array $config, string $name): void
    {
        if (isset($config['id_type'])) {
            $builder->idType(self::str($config['id_type'], ''));
        }
        if (isset($config['sync'])) {
            $builder->sync(self::str($config['sync'], ''));
        }
        if (isset($config['trigger_level'])) {
            $builder->triggerLevel(self::str($config['trigger_level'], ''));
        }
        if (isset($config['language']) || isset($config['unaccent'])) {
            $builder->language(self::str($config['language'] ?? null, 'english'), ($config['unaccent'] ?? true) === true);
        }
        if (isset($config['boost'])) {
            $builder->boostBy(self::str($config['boost'], ''));
        }
        if (isset($config['recency'])) {
            $builder->recencyBy(self::str($config['recency'], ''));
        }
        if (isset($config['tenant'])) {
            $builder->tenant(self::str($config['tenant'], ''));
        }
        if (isset($config['log'])) {
            $builder->log($config['log'] === true);
        }
        if (isset($config['synonyms'])) {
            $builder->synonyms($this->synonymEntries($name, $config['synonyms']));
        }
        foreach ($this->profiles($name, $config['profiles'] ?? []) as $profileName => $profile) {
            $builder->profile($profileName, $profile);
        }
        if (isset($config['thresholds'])) {
            $builder->thresholds($this->scoped($name, 'thresholds', static fn(): Thresholds => (new Thresholds())->with(self::map($config['thresholds']))));
        }
    }

    /** @return array<string, RankingProfile> */
    private function profiles(string $index, mixed $config): array
    {
        $profiles = [];
        foreach (self::map($config) as $name => $options) {
            $profiles[$name] = $this->scoped($index, sprintf('profile "%s"', $name), static fn(): RankingProfile => RankingProfile::fromArray(self::map($options)));
        }

        return $profiles;
    }

    /**
     * Runs a part whose own validation names no index (thresholds, a ranking profile) and puts the real index
     * name and the place on its problems.
     *
     * @template T
     *
     * @param \Closure(): T $part
     *
     * @return T
     */
    private function scoped(string $index, string $place, \Closure $part): mixed
    {
        try {
            return $part();
        } catch (InvalidDefinition $e) {
            $where = str_starts_with($e->index, $place) ? $e->index : $place;
            throw new InvalidDefinition($index, array_map(static fn(string $violation): string => sprintf('%s: %s', $where, $violation), $e->violations));
        }
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function str(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A "columns: [...]" value: null (absent or explicit null) means "no filtering", same as
     * before this option existed. A non-array scalar or an array containing a non-string entry
     * is a config error (most commonly a bare "columns: name" typo for "columns: [name]"), so it
     * is rejected loudly rather than silently treated as "no filtering" too.
     *
     * @return list<string>|null
     */
    private static function stringList(string $name, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $strings = array_filter($value, 'is_string');
            if (count($strings) === count($value)) {
                $list = array_values($strings);

                return $list !== [] ? $list : null;
            }
        }

        throw new InvalidDefinition($name, [sprintf(
            'Watch "columns" must be a list of strings (e.g. columns: [name]), got %s.',
            is_array($value) ? 'an array containing a non-string entry' : get_debug_type($value),
        )]);
    }

    /** @return array<array-key, string> a list of names, or alias => column */
    private static function returnableList(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string') : [];
    }

    /**
     * Every structural problem of the configuration in one InvalidDefinition: unknown keys at every level (with a
     * "did you mean"), values of the wrong shape (a list where a map belongs, a string where a boolean belongs; the
     * string "false" would switch an option on) and, for an override, the keys that describe the index itself.
     *
     * @param array<string, mixed> $config
     */
    private function assertShape(string $name, array $config, bool $override): void
    {
        $problems = [];
        $allowed = $override ? self::OVERRIDABLE : self::KEYS;
        $unknown = array_values(array_diff(array_map('strval', array_keys($config)), self::KEYS));
        if ($unknown !== []) {
            $problems[] = sprintf('Unknown option(s): %s. Allowed: %s.%s', implode(', ', $unknown), implode(', ', $allowed), self::hints($unknown, $allowed));
        }
        if ($override) {
            foreach (array_diff(array_intersect(array_map('strval', array_keys($config)), self::KEYS), self::OVERRIDABLE) as $key) {
                $problems[] = sprintf('"%s" cannot be set by a YAML override: index "%s" is defined by attributes, which stay primary; change the attributes (YAML can set %s).', $key, $name, implode(', ', self::OVERRIDABLE));
            }
        }
        foreach (self::STRING_KEYS as $key) {
            if (isset($config[$key]) && !is_scalar($config[$key])) {
                $problems[] = sprintf('"%s" must be a string, got %s.', $key, self::shape($config[$key]));
            }
        }
        foreach (self::BOOL_KEYS as $key) {
            self::assertBool($problems, sprintf('"%s"', $key), $config[$key] ?? null);
        }
        if (isset($config['class']) && !$override && (!is_string($config['class']) || !class_exists($config['class']))) {
            $problems[] = sprintf('"class" must be the name of an existing class, got %s.', self::describe($config['class']));
        }
        if (!$override) {
            $this->assertSources($problems, $config);
            $this->assertSource($problems, $config['source'] ?? null);
            $this->assertEntries($problems, 'fields', $config['fields'] ?? null, 'a weight letter (A-D)', self::FIELD_KEYS, 'field', 'fields: { title: A, body: { weight: C, fuzzy: true } }');
            $this->assertEntries($problems, 'filters', $config['filters'] ?? null, 'a filter type', self::FILTER_KEYS, 'filter', 'filters: { price: int }');
        }
        $this->assertEntries($problems, 'watch', $config['watch'] ?? null, 'a query returning the affected ids', self::WATCH_KEYS, 'watch', 'watch: { product: "SELECT :id" }');
        self::assertReturnable($problems, '"returnable"', $config['returnable'] ?? null);
        $this->assertProfiles($problems, $config['profiles'] ?? null);
        if (isset($config['thresholds']) && !self::isMap($config['thresholds'])) {
            $problems[] = sprintf('"thresholds" must be a map of options (e.g. thresholds: { relevance: { min_score: 0.1 } }), got %s.', self::shape($config['thresholds']));
        }
        if ($problems !== []) {
            throw new InvalidDefinition($name, $problems);
        }
    }

    /** @param list<string> $problems */
    private static function assertReturnable(array &$problems, string $what, mixed $value): void
    {
        if ($value !== null && (!is_array($value) || array_filter($value, 'is_string') !== $value)) {
            $problems[] = sprintf('%s must be a list of source column names (e.g. returnable: [title, slug, price]; `alias: column` entries rename one), got %s.', $what, self::shape($value));
        }
    }

    /** @param list<string> $problems */
    private static function assertBool(array &$problems, string $what, mixed $value): void
    {
        if ($value !== null && !is_bool($value)) {
            $problems[] = sprintf('%s must be true or false (a boolean, not a string), got %s.', $what, self::describe($value));
        }
    }

    /**
     * @param list<string>         $problems
     * @param array<string, mixed> $config
     */
    private function assertSources(array &$problems, array $config): void
    {
        if (!isset($config['sources'])) {
            return;
        }
        $sources = $config['sources'];
        foreach (['source', 'watch', 'id_type', 'class'] as $key) {
            if (isset($config[$key])) {
                $problems[] = sprintf('"sources" and "%s" are exclusive: with several sources, "%s" belongs inside each source%s.', $key, $key, $key === 'source' ? ' (an index has one "source" or several "sources")' : '');
            }
        }
        if (!self::isMap($sources) || $sources === []) {
            $problems[] = sprintf('"sources" must be a map of source name => { query | query_file | table, watch, entity, id_type } (e.g. sources: { product: { table: product } }), got %s.', self::shape($sources));

            return;
        }
        /** @var array<string, mixed> $sources */
        foreach ($sources as $name => $options) {
            $at = sprintf('Source "%s"', $name);
            if (!is_array($options) || array_is_list($options) && $options !== []) {
                $problems[] = sprintf('%s must be a map: { table: product } or { query: "SELECT ..." }, got %s.', $at, self::shape($options));

                continue;
            }
            $this->assertKeys($problems, $at, array_keys($options), self::SOURCES_KEYS);
            $given = array_filter(['table', 'query', 'query_file'], static fn(string $k): bool => isset($options[$k]));
            if (count($given) > 1) {
                $problems[] = sprintf('%s takes one of "table", "query" or "query_file", not %s.', $at, implode(' and ', $given));
            }
            if (isset($options['entity']) && (!is_string($options['entity']) || !class_exists($options['entity']))) {
                $problems[] = sprintf('%s: "entity" must be the name of an existing class, got %s.', $at, self::describe($options['entity']));
            }
            self::assertReturnable($problems, $at . ': "returnable"', $options['returnable'] ?? null);
            if (isset($options['watch'])) {
                $this->assertEntries($problems, 'watch', $options['watch'], 'a query returning the affected ids', self::WATCH_KEYS, 'watch', 'watch: { product: "SELECT :id" }');
            }
        }
    }

    /** @param list<string> $problems */
    private function assertSource(array &$problems, mixed $source): void
    {
        if ($source === null) {
            return;
        }
        if (!is_array($source) || array_is_list($source)) {
            $problems[] = sprintf('"source" must be a map: { table: product } or { query: "SELECT ..." }, got %s.', self::shape($source));

            return;
        }
        $this->assertKeys($problems, '"source"', array_keys($source), self::SOURCE_KEYS);
        if (array_key_exists('query_file', $source)) {
            if (!is_string($source['query_file'])) {
                $problems[] = sprintf('"source.query_file" must be the path of an SQL file, got %s.', self::describe($source['query_file']));
            }
            foreach (['query', 'table'] as $other) {
                if (isset($source[$other])) {
                    $problems[] = sprintf('"source" takes "query_file" or "%s", not both.', $other);
                }
            }
        }
    }

    /**
     * fields, filters and watch: a map of name => shorthand string or map of options.
     *
     * @param list<string> $problems
     * @param list<string> $allowed
     */
    private function assertEntries(array &$problems, string $section, mixed $value, string $shorthand, array $allowed, string $what, string $example): void
    {
        if ($value === null) {
            return;
        }
        if (!self::isMap($value)) {
            $problems[] = sprintf('"%s" must be a map of %s name => %s or options (e.g. %s), got %s.', $section, $what, $shorthand, $example, self::shape($value));

            return;
        }
        /** @var array<string, mixed> $value */
        foreach ($value as $key => $options) {
            $at = sprintf('%s "%s"', ucfirst($what), $key);
            if ($options === null || is_string($options)) {
                continue;
            }
            if (!is_array($options)) {
                $problems[] = sprintf('%s must be %s or a map of options (%s), got %s.', $at, $shorthand, implode(', ', $allowed), self::describe($options));

                continue;
            }
            $this->assertKeys($problems, $at, array_keys($options), $allowed);
            if (array_key_exists('many', $options)) {
                $this->assertMany($problems, $at, $options['many']);
            }
            if ($section === 'fields') {
                self::assertBool($problems, $at . ' "fuzzy"', $options['fuzzy'] ?? null);
                self::assertBool($problems, $at . ' "highlight"', $options['highlight'] ?? null);
                self::assertBool($problems, $at . ' "code"', $options['code'] ?? null);
                if (array_key_exists('granted', $options) && (!is_string($options['granted']) || trim($options['granted']) === '')) {
                    // never read as "open": a field meant to be protected must not become visible to everyone by a typo or an empty value
                    $problems[] = sprintf('%s "granted" must be a non-empty string, the attribute that unlocks the field (granted: ROLE_HR), got %s. Remove the key to leave the field open to everyone.', $at, self::describe($options['granted']));
                }
            }
        }
    }

    /** @param list<string> $problems */
    private function assertMany(array &$problems, string $at, mixed $many): void
    {
        if (!self::isMap($many)) {
            $problems[] = sprintf('%s "many" must be a map { from: address, key: customer_id, column: city, limit: 50 }, got %s.', $at, self::describe($many));

            return;
        }
        /** @var array<string, mixed> $many */
        $this->assertKeys($problems, $at . ' "many"', array_keys($many), ManyValues::KEYS);
        foreach (['from', 'key', 'column'] as $required) {
            if (!is_string($many[$required] ?? null) || $many[$required] === '') {
                $problems[] = sprintf('%s "many" needs "%s" (a non-empty string), e.g. many: { from: address, key: customer_id, column: city }.', $at, $required);
            }
        }
        if (array_key_exists('limit', $many) && !is_int($many['limit'])) {
            $problems[] = sprintf('%s "many" "limit" must be an integer, got %s.', $at, self::describe($many['limit']));
        }
        if (array_key_exists('id', $many) && (!is_string($many['id']) || $many['id'] === '')) {
            $problems[] = sprintf('%s "many" "id" must be the primary key column of the child table (a non-empty string), got %s.', $at, self::describe($many['id']));
        }
    }

    /** @param list<string> $problems */
    private function assertProfiles(array &$problems, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (!self::isMap($value)) {
            $problems[] = sprintf('"profiles" must be a map of profile name => ranking options (e.g. profiles: { popular: { boost: 0.2 } }), got %s.', self::shape($value));

            return;
        }
        /** @var array<string, mixed> $value */
        foreach ($value as $key => $options) {
            if (!is_array($options)) {
                $problems[] = sprintf('Profile "%s" must be a map of ranking options, got %s.', $key, self::shape($options));
            }
        }
    }

    /**
     * @param list<string>      $problems
     * @param array<int|string> $keys
     * @param list<string>      $allowed
     */
    private function assertKeys(array &$problems, string $at, array $keys, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', $keys), $allowed));
        if ($unknown !== []) {
            $problems[] = sprintf('%s: unknown option(s): %s. Allowed: %s.%s', $at, implode(', ', $unknown), implode(', ', $allowed), self::hints($unknown, $allowed));
        }
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $allowed
     */
    private static function hints(array $unknown, array $allowed): string
    {
        $hints = [];
        foreach ($unknown as $key) {
            $closest = Closest::of($key, $allowed);
            if ($closest !== null) {
                $hints[] = sprintf('"%s" for "%s"', $closest, $key);
            }
        }

        return $hints === [] ? '' : ' Did you mean ' . implode(', ', $hints) . '?';
    }

    /** An empty array is a map (an empty YAML section), a list is not: `fields: [name, body]` is a mistake. */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }

    /** What a value is, for a section that wants a map: a list is the usual mistake. */
    private static function shape(mixed $value): string
    {
        return is_array($value) ? (array_is_list($value) ? 'a list' : 'a map') : self::describe($value);
    }

    /** @return array<array-key, mixed> */
    private function synonymEntries(string $index, mixed $value): array
    {
        if (is_array($value) && !array_is_list($value) && array_diff(array_keys($value), ['file', 'entries']) === []) {
            $file = $value['file'] ?? null;
            $inline = $value['entries'] ?? [];
            if ((!is_string($file) && $file !== null) || !is_array($inline)) {
                throw new InvalidDefinition($index, ['"synonyms" as a map takes "file" (a path) and "entries" (a list of groups and rules).']);
            }

            return [...is_string($file) ? Synonyms::fromFile($file, $index)->toEntries() : [], ...$inline];
        }

        return is_array($value) ? $value : throw new InvalidDefinition($index, ['"synonyms" must be a list of groups and rules, e.g. [[tv, television], "laptop => notebook"]; got ' . get_debug_type($value) . '.']);
    }

    private function synonyms(string $index, mixed $value): Synonyms
    {
        return Synonyms::fromEntries($this->synonymEntries($index, $value ?? []), $index);
    }
}
