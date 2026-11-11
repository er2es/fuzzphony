<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * Complete, immutable description of one search index. Build it with IndexDefinition::builder(),
 * from #[Searchable] attributes, or from YAML/array configuration.
 *
 * Withers return a changed copy and do not validate; `IndexRegistry::register()` validates every
 * definition it accepts.
 */
final readonly class IndexDefinition
{
    /**
     * @param list<FieldDefinition>         $fields
     * @param list<FilterDefinition>        $filters
     * @param list<Watch>                   $watches  Extra tables whose changes affect documents (joins).
     * @param array<string, RankingProfile> $profiles Must contain "default".
     * @param class-string|null             $entityClass
     *
     * @internal
     */
    public function __construct(
        public string $name,
        public Source $source,
        public array $fields,
        public array $filters = [],
        public array $watches = [],
        public IdType $idType = IdType::Int,
        public SyncMode $sync = SyncMode::Queue,
        public TextConfig $text = new TextConfig(),
        public ?string $boostColumn = null,
        public ?string $recencyColumn = null,
        public array $profiles = ['default' => new RankingProfile()],
        public Thresholds $thresholds = new Thresholds(),
        public ?string $entityClass = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
        /** The name of the FilterDefinition that scopes every search to one tenant; null = not tenant-scoped. */
        public ?string $tenant = null,
        /** Query-side synonyms; they are not part of the index table, so changing them needs no reindex. */
        public Synonyms $synonyms = new Synonyms(),
        /** Whether searches on this index go to the search log; not part of the layout, so changing it needs no reindex. */
        public bool $log = true,
        /**
         * The sources of a multi-source index (empty: a single-source index, `$source` is its source). A multi-source index has
         * `$idType` String (its stored key is the text `<kind>:<id>`), `$source` is its first source (never read for a multi-source one).
         *
         * @var list<IndexSource>
         */
        public array $sources = [],
        /** Narrows the definition to one source for a per-source reindex (never part of a registered definition). */
        public ?string $onlySource = null,
        /**
         * The columns of the source a search may return with SearchBuilder::with() (Hit::$data). A multi-source index declares them per
         * source (IndexSource::$returnable) and this is empty.
         *
         * @var list<string>
         */
        public array $returnable = [],
        /**
         * The returnable columns that are returned under another name (alias => column, Hit::$data is keyed by the alias).
         *
         * @var array<string, string>
         */
        public array $returnableAs = [],
    ) {}

    public static function builder(string $name): IndexBuilder
    {
        return new IndexBuilder($name);
    }

    public function field(string $name): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The filter a facet may count the values of: a string, int, bool or date filter that is not the
     * tenant filter (a facet over it would list other tenants' values).
     *
     * @throws InvalidQuery
     */
    public function assertFacetable(string $name): FilterDefinition
    {
        $filter = $this->filter($name);
        if ($name === $this->tenant) {
            throw new InvalidQuery(sprintf('"%s" is the tenant filter of index "%s": a facet over it would list other tenants\' values.', $name, $this->name));
        }
        if (in_array($filter->type, [FilterType::Float, FilterType::DateTime], true)) {
            throw new InvalidQuery(sprintf('Filter "%s" is a %s: facets count the values of string, int, bool and date filters.', $name, $filter->type->value));
        }

        return $filter;
    }

    public function filter(string $name): FilterDefinition
    {
        foreach ($this->filters as $filter) {
            if ($filter->name === $name) {
                return $filter;
            }
        }

        throw InvalidQuery::unknownFilter($this->name, $name, array_map(static fn(FilterDefinition $f): string => $f->name, $this->filters));
    }

    public function profile(string $name): RankingProfile
    {
        return $this->profiles[$name] ?? throw InvalidQuery::unknownProfile($this->name, $name, array_keys($this->profiles));
    }

    /** Whether a field names a role that unlocks it (`granted`): such a field is only hidden by a guard. */
    public function hasGrantedField(): bool
    {
        return array_any($this->fields, static fn(FieldDefinition $f): bool => $f->granted !== null);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        return array_map(static fn(FieldDefinition $f): string => $f->name, $this->fields);
    }

    /** @return list<FieldDefinition> */
    public function fuzzyFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldDefinition $f): bool => $f->fuzzy));
    }

    public function hasFuzzy(): bool
    {
        return $this->fuzzyFields() !== [];
    }

    /** The field used for exact / prefix bonuses: the first A-weighted field, else the first field. */
    public function primaryField(): FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->weight === Weight::A) {
                return $field;
            }
        }

        return $this->fields[0];
    }

    /**
     * Watches including the source table itself (for table sources).
     *
     * @return list<Watch>
     */
    public function effectiveWatches(): array
    {
        if ($this->sources !== []) {
            return array_merge(...array_map(static fn(IndexSource $s): array => $s->effectiveWatches(), $this->sources));
        }
        $watches = $this->watches;
        foreach ($this->manyWatches() as $derived) {
            if (!array_any($watches, static fn(Watch $w): bool => $w->table === $derived->table)) {
                $watches[] = $derived;
            }
        }
        if ($this->source->table !== null) {
            $own = false;
            foreach ($watches as $watch) {
                $own = $own || $watch->table === $this->source->table;
            }
            if (!$own) {
                array_unshift($watches, new Watch($this->source->table, 'SELECT :id', $this->source->idColumn));
            }
        }

        return $watches;
    }

    /** @return list<FieldDefinition> the searchable fields whose values come from a child table */
    public function manyFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldDefinition $f): bool => $f->many !== null));
    }

    /** @return list<FieldDefinition> the many fields that rank through their best single value (`many: { rank: best }`) */
    public function manyBestFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldDefinition $f): bool => $f->many?->rank === ManyRank::Best));
    }

    /** @return list<FilterDefinition> the filters whose values come from a child table */
    public function manyFilters(): array
    {
        return array_values(array_filter($this->filters, static fn(FilterDefinition $f): bool => $f->many !== null));
    }

    /** Whether any field or filter takes several values from a child table (such an index gets the values table). */
    public function hasMany(): bool
    {
        return $this->manyFields() !== [] || $this->manyFilters() !== [];
    }

    /**
     * Every child table the many fields and filters read, with the key column that links a row to its document; the sync watches
     * of these tables are derived from here (a change of a child row refreshes its owning document only).
     *
     * @return list<Watch>
     */
    public function manyWatches(): array
    {
        $byTable = [];
        foreach ([...$this->manyFields(), ...$this->manyFilters()] as $one) {
            $many = $one->many;
            if ($many === null) {
                continue;
            }
            $byTable[$many->from]['key'] = $many->key;
            $byTable[$many->from]['columns'][] = $many->column;
        }

        return array_values(array_map(
            static fn(string $table, array $w): Watch => new Watch($table, 'SELECT :id', $w['key'], array_values(array_unique($w['columns']))),
            array_keys($byTable),
            $byTable,
        ));
    }

    public function isMultiSource(): bool
    {
        return $this->sources !== [];
    }

    public function sourceNamed(string $name): ?IndexSource
    {
        foreach ($this->sources as $source) {
            if ($source->name === $name) {
                return $source;
            }
        }

        return null;
    }

    /** Whether any source of the index declares returnable columns (a search may then ask for them with with()). */
    public function hasReturnable(): bool
    {
        return $this->returnableNames() !== [];
    }

    /**
     * Every name a search may ask for with with(): the returnable columns of the index (single-source) or of its sources (their union).
     *
     * @return list<string>
     */
    public function returnableNames(): array
    {
        if ($this->sources === []) {
            return [...$this->returnable, ...array_keys($this->returnableAs)];
        }

        return array_values(array_unique(array_merge([], ...array_map(static fn(IndexSource $s): array => [...$s->returnable, ...array_keys($s->returnableAs)], $this->sources))));
    }

    /**
     * The returnable columns of one kind (a single-source index: its own).
     *
     * @return list<string>
     */
    public function returnableOf(string $kind): array
    {
        if ($this->sources === []) {
            return [...$this->returnable, ...array_keys($this->returnableAs)];
        }
        $source = $this->sourceNamed($kind);

        return $source === null ? [] : [...$source->returnable, ...array_keys($source->returnableAs)];
    }

    /**
     * The source column a returnable name reads (the name itself unless it is an alias); null when the kind does not return that name.
     */
    public function returnableColumn(string $kind, string $name): ?string
    {
        if ($this->sources === []) {
            $plain = $this->returnable;
            $aliases = $this->returnableAs;
        } else {
            $source = $this->sourceNamed($kind);
            $plain = $source->returnable ?? [];
            $aliases = $source->returnableAs ?? [];
        }

        return $aliases[$name] ?? (in_array($name, $plain, true) ? $name : null);
    }

    /**
     * Every column a returnable name of the whole index may read (one per source for a multi-source index), for the gate that must
     * see through an alias.
     *
     * @return list<string>
     */
    public function returnableColumnsOf(string $name): array
    {
        $columns = [];
        foreach ($this->sources === [] ? [''] : array_map(static fn(IndexSource $s): string => $s->name, $this->sources) as $kind) {
            $column = $this->returnableColumn($kind, $name);
            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * The many fields with() may return the values of (a returnable name that is a many field, directly or through an alias).
     *
     * @return list<FieldDefinition>
     */
    public function returnableManyFields(): array
    {
        $out = [];
        foreach ($this->returnableNames() as $name) {
            foreach ($this->returnableColumnsOf($name) as $column) {
                $field = $this->field($column);
                if ($field?->many !== null) {
                    $out[$field->name] = $field;
                }
            }
        }

        return array_values($out);
    }

    /**
     * The plain source columns the returnable declaration reads, aliases resolved, many fields left out (they are not source columns):
     * what the doctor and the schema check must find in the source query.
     *
     * @return list<string>
     */
    public function returnableSourceColumns(): array
    {
        $out = [];
        foreach ($this->returnableNames() as $name) {
            foreach ($this->returnableColumnsOf($name) as $column) {
                if ($this->field($column)?->many === null) {
                    $out[] = $column;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** The source that reads an entity class (a multi-source index: each source may name one). */
    public function sourceForEntity(string $class): ?IndexSource
    {
        foreach ($this->sources as $source) {
            if ($source->entityClass === $class) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The sources a run works on: all of them, or the one a per-source reindex is narrowed to.
     *
     * @return list<IndexSource>
     */
    public function activeSources(): array
    {
        return $this->onlySource === null ? $this->sources : array_values(array_filter($this->sources, fn(IndexSource $s): bool => $s->name === $this->onlySource));
    }

    /** The source a watch belongs to (a single-source index: its source). */
    public function sourceOf(Watch $watch): Source
    {
        return ($watch->source !== null ? $this->sourceNamed($watch->source)?->source : null) ?? $this->source;
    }

    /**
     * The kind and the typed id of a stored key: a multi-source index splits `<kind>:<id>` and converts the id by that source's
     * id type; a single-source index's kind is its name.
     *
     * @return array{string, int|string}
     */
    public function parseKey(string $key): array
    {
        if ($this->sources === []) {
            return [$this->name, $this->idType->cast($key)];
        }
        $at = strpos($key, ':');
        $kind = $at === false ? '' : substr($key, 0, $at);
        $raw = $at === false ? $key : substr($key, $at + 1);

        return [$kind, ($this->sourceNamed($kind)->idType ?? IdType::String)->cast($raw)];
    }

    /** One source of a multi-source index as a single-source definition (the doctor checks each source on its own). */
    public function forSource(IndexSource $source): self
    {
        return $this->copy(source: $source->source, idType: $source->idType, watches: $source->watches, entityClass: $source->entityClass ?? false, sources: [], returnable: $source->returnable, returnableAs: $source->returnableAs);
    }

    /** A copy narrowed to one source (a per-source reindex). */
    public function withOnlySource(?string $name): self
    {
        return $this->copy(onlySource: $name === null ? '' : $name);
    }

    public function withName(string $name): self
    {
        return $this->copy(name: $name);
    }

    public function withSource(Source $source): self
    {
        return $this->copy(source: $source);
    }

    /** @param list<FieldDefinition> $fields */
    public function withFields(array $fields): self
    {
        return $this->copy(fields: $fields);
    }

    /** @param list<FilterDefinition> $filters */
    public function withFilters(array $filters): self
    {
        return $this->copy(filters: $filters);
    }

    /** @param list<Watch> $watches */
    public function withWatches(array $watches): self
    {
        return $this->copy(watches: $watches);
    }

    public function withIdType(IdType $idType): self
    {
        return $this->copy(idType: $idType);
    }

    public function withSync(SyncMode $sync): self
    {
        return $this->copy(sync: $sync);
    }

    public function withText(TextConfig $text): self
    {
        return $this->copy(text: $text);
    }

    public function withBoostColumn(?string $column): self
    {
        return $this->copy(boostColumn: $column);
    }

    public function withRecencyColumn(?string $column): self
    {
        return $this->copy(recencyColumn: $column);
    }

    /** @param array<string, RankingProfile> $profiles must contain "default" */
    public function withProfiles(array $profiles): self
    {
        return $this->copy(profiles: $profiles);
    }

    public function withThresholds(Thresholds $thresholds): self
    {
        return $this->copy(thresholds: $thresholds);
    }

    /** @param class-string|null $entityClass */
    public function withEntityClass(?string $entityClass): self
    {
        return $this->copy(entityClass: $entityClass);
    }

    public function withTriggerLevel(TriggerLevel $level): self
    {
        return $this->copy(triggerLevel: $level);
    }

    /** @param string|null $filter the tenant filter's name; null = not tenant-scoped */
    public function withTenant(?string $filter): self
    {
        return $this->copy(tenant: $filter);
    }

    public function withSynonyms(Synonyms $synonyms): self
    {
        return $this->copy(synonyms: $synonyms);
    }

    /** @param list<IndexSource> $sources */
    public function withSources(array $sources): self
    {
        return $this->copy(sources: $sources);
    }

    /**
     * @param list<string>          $returnable
     * @param array<string, string> $returnableAs
     */
    public function withReturnable(array $returnable, array $returnableAs = []): self
    {
        return $this->copy(returnable: $returnable, returnableAs: $returnableAs);
    }

    public function withLog(bool $log): self
    {
        return $this->copy(log: $log);
    }

    /**
     * The one place a copy is made, through the constructor. null keeps an object/array value;
     * false keeps a nullable string (so null can clear it).
     *
     * @param list<FieldDefinition>|null         $fields
     * @param list<FilterDefinition>|null        $filters
     * @param list<Watch>|null                   $watches
     * @param list<IndexSource>|null             $sources
     * @param list<string>|null                  $returnable
     * @param array<string, string>|null         $returnableAs
     * @param array<string, RankingProfile>|null $profiles
     * @param class-string|false|null            $entityClass
     */
    private function copy(
        ?string $name = null,
        ?Source $source = null,
        ?array $fields = null,
        ?array $filters = null,
        ?array $watches = null,
        ?IdType $idType = null,
        ?SyncMode $sync = null,
        ?TextConfig $text = null,
        string|false|null $boostColumn = false,
        string|false|null $recencyColumn = false,
        ?array $profiles = null,
        ?Thresholds $thresholds = null,
        string|false|null $entityClass = false,
        ?TriggerLevel $triggerLevel = null,
        string|false|null $tenant = false,
        ?Synonyms $synonyms = null,
        ?bool $log = null,
        string|null $onlySource = null,
        ?array $sources = null,
        ?array $returnable = null,
        ?array $returnableAs = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            source: $source ?? $this->source,
            fields: $fields ?? $this->fields,
            filters: $filters ?? $this->filters,
            watches: $watches ?? $this->watches,
            idType: $idType ?? $this->idType,
            sync: $sync ?? $this->sync,
            text: $text ?? $this->text,
            boostColumn: $boostColumn === false ? $this->boostColumn : $boostColumn,
            recencyColumn: $recencyColumn === false ? $this->recencyColumn : $recencyColumn,
            profiles: $profiles ?? $this->profiles,
            thresholds: $thresholds ?? $this->thresholds,
            entityClass: $entityClass === false ? $this->entityClass : $entityClass,
            triggerLevel: $triggerLevel ?? $this->triggerLevel,
            tenant: $tenant === false ? $this->tenant : $tenant,
            synonyms: $synonyms ?? $this->synonyms,
            log: $log ?? $this->log,
            sources: $sources ?? $this->sources,
            onlySource: $onlySource === null ? $this->onlySource : ($onlySource === '' ? null : $onlySource),
            returnable: $returnable ?? $this->returnable,
            returnableAs: $returnableAs ?? $this->returnableAs,
        );
    }
}
