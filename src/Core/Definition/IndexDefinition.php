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
        );
    }
}
