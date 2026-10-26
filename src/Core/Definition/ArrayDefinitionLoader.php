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
 *     source: { table: product, id: id }            # or { query: "SELECT ...", id: id }
 *     id_type: int
 *     fields: { name: { weight: A, fuzzy: true }, description: C, salary: { weight: B, granted: ROLE_HR } }
 *     filters: { price: int, in_stock: bool }
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
 *     thresholds: { min_score: 0.05, fuzzy_mode: fallback }
 *
 * The shape is checked as a whole before anything is built: unknown keys at every level, a list where a map
 * belongs, a string where a boolean belongs (the string "false" is not false) are InvalidDefinition problems.
 */
final class ArrayDefinitionLoader
{
    private const array KEYS = ['source', 'id_type', 'fields', 'filters', 'watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'class', 'trigger_level', 'tenant', 'synonyms', 'log'];

    /** What an override can change on an index its attributes define; the other keys describe the index itself. */
    private const array OVERRIDABLE = ['watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'trigger_level', 'tenant', 'synonyms', 'log'];

    private const array SOURCE_KEYS = ['table', 'query', 'id'];
    private const array FIELD_KEYS = ['weight', 'fuzzy', 'highlight', 'column', 'granted'];
    private const array FILTER_KEYS = ['type', 'column'];
    private const array WATCH_KEYS = ['ids', 'key', 'columns'];
    private const array STRING_KEYS = ['id_type', 'sync', 'trigger_level', 'language', 'boost', 'recency', 'tenant'];
    private const array BOOL_KEYS = ['unaccent', 'log'];

    /** @param array<string, mixed> $config */
    public function load(string $name, array $config): IndexDefinition
    {
        $this->assertShape($name, $config, false);
        $builder = IndexDefinition::builder($name);

        /** @var array{table?: string, query?: string, id?: string} $source */
        $source = $config['source'] ?? ['table' => $name];
        isset($source['query'])
            ? $builder->fromQuery($source['query'], $source['id'] ?? 'id')
            : $builder->fromTable($source['table'] ?? $name, $source['id'] ?? 'id');

        foreach (self::map($config['fields'] ?? []) as $field => $options) {
            $options = is_string($options) ? ['weight' => $options] : self::map($options);
            $builder->field($field, self::str($options['weight'] ?? null, 'B'), ($options['fuzzy'] ?? false) === true, ($options['highlight'] ?? true) === true, isset($options['column']) ? self::str($options['column'], '') : null, isset($options['granted']) ? self::str($options['granted'], '') : null);
        }
        foreach (self::map($config['filters'] ?? []) as $filter => $options) {
            $options = is_string($options) ? ['type' => $options] : self::map($options);
            $builder->filter($filter, self::str($options['type'] ?? null, ''), isset($options['column']) ? self::str($options['column'], '') : null);
        }
        foreach (self::map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : self::map($options);
            $builder->watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($name, $options['columns'] ?? null));
        }

        $this->apply($builder, $config, $name);
        if (is_string($config['class'] ?? null) && class_exists($config['class'])) {
            $builder->entity($config['class']);
        }

        return $builder->build();
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
        $watches = $definition->watches;
        foreach (self::map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : self::map($options);
            $watches[] = new Watch($table, self::str($options['ids'] ?? null, 'SELECT :id'), self::str($options['key'] ?? null, 'id'), self::stringList($name, $options['columns'] ?? null));
        }
        $merged = $merged->withWatches($watches);
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
            throw new InvalidDefinition($index, array_map(static fn(string $violation): string => sprintf('%s: %s', $place, $violation), $e->violations));
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
            $this->assertSource($problems, $config['source'] ?? null);
            $this->assertEntries($problems, 'fields', $config['fields'] ?? null, 'a weight letter (A-D)', self::FIELD_KEYS, 'field', 'fields: { title: A, body: { weight: C, fuzzy: true } }');
            $this->assertEntries($problems, 'filters', $config['filters'] ?? null, 'a filter type', self::FILTER_KEYS, 'filter', 'filters: { price: int }');
        }
        $this->assertEntries($problems, 'watch', $config['watch'] ?? null, 'a query returning the affected ids', self::WATCH_KEYS, 'watch', 'watch: { product: "SELECT :id" }');
        $this->assertProfiles($problems, $config['profiles'] ?? null);
        if (isset($config['thresholds']) && !self::isMap($config['thresholds'])) {
            $problems[] = sprintf('"thresholds" must be a map of options (e.g. thresholds: { min_score: 0.1 }), got %s.', self::shape($config['thresholds']));
        }
        if ($problems !== []) {
            throw new InvalidDefinition($name, $problems);
        }
    }

    /** @param list<string> $problems */
    private static function assertBool(array &$problems, string $what, mixed $value): void
    {
        if ($value !== null && !is_bool($value)) {
            $problems[] = sprintf('%s must be true or false (a boolean, not a string), got %s.', $what, self::describe($value));
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
            if ($section === 'fields') {
                self::assertBool($problems, $at . ' "fuzzy"', $options['fuzzy'] ?? null);
                self::assertBool($problems, $at . ' "highlight"', $options['highlight'] ?? null);
            }
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
