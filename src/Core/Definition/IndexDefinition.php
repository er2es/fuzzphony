<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * Complete, immutable description of one search index. Build it with IndexDefinition::builder(),
 * from #[Searchable] attributes, or from YAML/array configuration.
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
    ) {}

    public static function builder(string $name): IndexBuilder
    {
        return new IndexBuilder($name);
    }

    public function sidecarTable(): string
    {
        return 'fuzzphony_' . $this->name;
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

    /**
     * Returns a copy with some properties replaced (used to merge YAML overrides into attribute definitions).
     * Each override is validated against its real property type; an absent or wrong-typed key keeps the current value.
     */
    public function with(mixed ...$changes): self
    {
        $name = $changes['name'] ?? null;
        $source = $changes['source'] ?? null;
        $fields = $changes['fields'] ?? null;
        $filters = $changes['filters'] ?? null;
        $watches = $changes['watches'] ?? null;
        $idType = $changes['idType'] ?? null;
        $sync = $changes['sync'] ?? null;
        $text = $changes['text'] ?? null;
        $boostColumn = array_key_exists('boostColumn', $changes) ? $changes['boostColumn'] : $this->boostColumn;
        $recencyColumn = array_key_exists('recencyColumn', $changes) ? $changes['recencyColumn'] : $this->recencyColumn;
        $profiles = $changes['profiles'] ?? null;
        $thresholds = $changes['thresholds'] ?? null;
        $entityClass = array_key_exists('entityClass', $changes) ? $changes['entityClass'] : $this->entityClass;
        $triggerLevel = $changes['triggerLevel'] ?? null;

        $entityClassOverride = $this->entityClass;
        if (array_key_exists('entityClass', $changes)) {
            $candidate = $changes['entityClass'];
            if ($candidate === null) {
                $entityClassOverride = null;
            } elseif (is_string($candidate) && class_exists($candidate)) {
                $entityClassOverride = $candidate;
            }
        }

        return new self(
            name: is_string($name) ? $name : $this->name,
            source: $source instanceof Source ? $source : $this->source,
            fields: self::typedList($fields, FieldDefinition::class) ?? $this->fields,
            filters: self::typedList($filters, FilterDefinition::class) ?? $this->filters,
            watches: self::typedList($watches, Watch::class) ?? $this->watches,
            idType: $idType instanceof IdType ? $idType : $this->idType,
            sync: $sync instanceof SyncMode ? $sync : $this->sync,
            text: $text instanceof TextConfig ? $text : $this->text,
            boostColumn: is_string($boostColumn) || $boostColumn === null ? $boostColumn : $this->boostColumn,
            recencyColumn: is_string($recencyColumn) || $recencyColumn === null ? $recencyColumn : $this->recencyColumn,
            profiles: self::typedMap($profiles, RankingProfile::class) ?? $this->profiles,
            thresholds: $thresholds instanceof Thresholds ? $thresholds : $this->thresholds,
            entityClass: $entityClassOverride,
            triggerLevel: $triggerLevel instanceof TriggerLevel ? $triggerLevel : $this->triggerLevel,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $of
     *
     * @return list<T>|null
     */
    private static function typedList(mixed $value, string $of): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (!$item instanceof $of) {
                return null;
            }
        }

        return array_values($value);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $of
     *
     * @return array<string, T>|null
     */
    private static function typedMap(mixed $value, string $of): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !$item instanceof $of) {
                return null;
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
