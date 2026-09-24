<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * Fluent, validating builder:
 *
 *   IndexDefinition::builder('products')
 *       ->fromTable('product')
 *       ->field('name', 'A', fuzzy: true)
 *       ->field('description', 'C')
 *       ->filter('price', 'int')
 *       ->build();
 */
final class IndexBuilder
{
    private ?Source $source = null;
    /** @var list<FieldDefinition> */
    private array $fields = [];
    /** @var list<FilterDefinition> */
    private array $filters = [];
    /** @var list<Watch> */
    private array $watches = [];
    private IdType $idType = IdType::Int;
    private SyncMode $sync = SyncMode::Queue;
    private TriggerLevel $triggerLevel = TriggerLevel::Statement;
    private TextConfig $text;
    private ?string $boost = null;
    private ?string $recency = null;
    private ?string $tenant = null;
    /** @var array<string, RankingProfile> */
    private array $profiles = [];
    private Thresholds $thresholds;
    /** @var class-string|null */
    private ?string $entityClass = null;

    public function __construct(private readonly string $name)
    {
        $this->text = new TextConfig();
        $this->thresholds = new Thresholds();
    }

    public function fromTable(string $table, string $idColumn = 'id'): self
    {
        $this->source = Source::table($table, $idColumn);

        return $this;
    }

    /** Any SELECT returning one row per document (joins welcome). Remember to watch() joined tables. */
    public function fromQuery(string $sql, string $idColumn = 'id'): self
    {
        $this->source = Source::query($sql, $idColumn);

        return $this;
    }

    public function field(string $name, Weight|string $weight = Weight::B, bool $fuzzy = false, bool $highlight = true, ?string $column = null): self
    {
        $this->fields[] = new FieldDefinition($name, Weight::parse($weight), $fuzzy, $highlight, $column);

        return $this;
    }

    public function filter(string $name, FilterType|string $type, ?string $column = null): self
    {
        $this->filters[] = new FilterDefinition($name, $type instanceof FilterType ? $type : FilterType::from($type), $column);

        return $this;
    }

    public function watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id'): self
    {
        $this->watches[] = new Watch($table, $affectedIds, $keyColumn);

        return $this;
    }

    public function idType(IdType|string $type): self
    {
        $this->idType = $type instanceof IdType ? $type : IdType::from($type);

        return $this;
    }

    public function sync(SyncMode|string $mode): self
    {
        $this->sync = $mode instanceof SyncMode ? $mode : SyncMode::from($mode);

        return $this;
    }

    public function triggerLevel(TriggerLevel|string $level): self
    {
        $this->triggerLevel = $level instanceof TriggerLevel ? $level : TriggerLevel::from($level);

        return $this;
    }

    public function language(string $language, bool $unaccent = true): self
    {
        $this->text = new TextConfig($language, $unaccent);

        return $this;
    }

    public function boostBy(string $column): self
    {
        $this->boost = $column;

        return $this;
    }

    public function recencyBy(string $column): self
    {
        $this->recency = $column;

        return $this;
    }

    /** Marks an already-declared filter() as the tenant scope: every search must supply forTenant(). */
    public function tenant(string $filterName): self
    {
        $this->tenant = $filterName;

        return $this;
    }

    public function profile(string $name, RankingProfile $profile): self
    {
        $this->profiles[$name] = $profile;

        return $this;
    }

    public function thresholds(Thresholds $thresholds): self
    {
        $this->thresholds = $thresholds;

        return $this;
    }

    /** @param class-string $class */
    public function entity(string $class): self
    {
        $this->entityClass = $class;

        return $this;
    }

    public function build(): IndexDefinition
    {
        $definition = new IndexDefinition(
            name: $this->name,
            source: $this->source ?? Source::table($this->name),
            fields: $this->fields,
            filters: $this->filters,
            watches: $this->watches,
            idType: $this->idType,
            sync: $this->sync,
            text: $this->text,
            boostColumn: $this->boost,
            recencyColumn: $this->recency,
            profiles: $this->profiles + ['default' => new RankingProfile()],
            thresholds: $this->thresholds,
            entityClass: $this->entityClass,
            triggerLevel: $this->triggerLevel,
            tenant: $this->tenant,
        );
        DefinitionValidator::assertValid($definition);

        return $definition;
    }
}
