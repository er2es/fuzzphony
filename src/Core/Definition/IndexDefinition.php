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

    /** The sources a run works on: all of them, or the one a per-source reindex is narrowed to. @return list<IndexSource> */
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

        return [$kind, ($this->sourceNamed($kind)?->idType ?? IdType::String)->cast($raw)];
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
        );
    }
}
