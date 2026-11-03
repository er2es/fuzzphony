<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
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
    private Synonyms $synonyms;
    private bool $log = true;
    /** @var array<string, RankingProfile> */
    private array $profiles = [];
    private Thresholds $thresholds;
    /** @var class-string|null */
    private ?string $entityClass = null;
    /** @var list<IndexSource> */
    private array $sources = [];
    /** @var list<string> */
    private array $sourceProblems = [];

    public function __construct(private readonly string $name)
    {
        $this->text = new TextConfig();
        $this->thresholds = new Thresholds();
        $this->synonyms = new Synonyms();
    }

    public function fromTable(string $table, string $idColumn = 'id'): self
    {
        $this->source = Source::table($table, $idColumn);

        return $this;
    }

    /**
     * Any SELECT returning one row per document (joins welcome). Remember to watch() joined tables.
     *
     * The SQL is trusted developer input and is embedded verbatim in generated DDL (functions and
     * triggers): never build it from user input. It must not contain the "$fuzzphony$" dollar-quote tag.
     */
    public function fromQuery(string $sql, string $idColumn = 'id'): self
    {
        $this->source = Source::query($sql, $idColumn);

        return $this;
    }

    /**
     * The source query from an SQL file, read now (so its content is part of the definition and its fingerprint). The path is relative
     * to $baseDir (default: the current working directory; the bundle uses the project root) and must stay inside it.
     */
    public function fromQueryFile(string $path, ?string $baseDir = null, string $idColumn = 'id'): self
    {
        return $this->fromQuery(QueryFile::read($this->name, $path, $baseDir), $idColumn);
    }

    /**
     * A source of a multi-source index: a SELECT returning `id` and every field and filter column of the index (alias your own
     * columns to the common names). The name is the `kind` of its hits. The SQL is trusted developer input (see fromQuery()).
     *
     * @param array<array-key, mixed>      $watch  Watch objects, or table => "SELECT ... :id" / table => { ids, key, columns } as in YAML
     * @param class-string|null            $entity
     */
    public function source(string $name, string $sql, ?string $entity = null, array $watch = [], IdType|string|null $idType = null, string $idColumn = 'id'): self
    {
        return $this->addSource($name, Source::query($sql, $idColumn), $entity, $watch, $idType);
    }

    /**
     * A source of a multi-source index read straight from a table (its columns must carry the index's field and filter names, or
     * the `column:` of each); the table is watched automatically.
     *
     * @param array<array-key, mixed> $watch
     * @param class-string|null       $entity
     */
    public function sourceFromTable(string $name, string $table, ?string $entity = null, array $watch = [], IdType|string|null $idType = null, string $idColumn = 'id'): self
    {
        return $this->addSource($name, Source::table($table, $idColumn), $entity, $watch, $idType);
    }

    /**
     * @param array<array-key, mixed> $watch
     * @param class-string|null       $entity
     */
    private function addSource(string $name, Source $source, ?string $entity, array $watch, IdType|string|null $idType): self
    {
        $type = $idType === null ? IdType::Int : ($idType instanceof IdType ? $idType : EnumOption::parse(IdType::class, $idType, $this->name, 'id type', sprintf(' of source "%s"', $name)));
        $this->sources[] = new IndexSource($name, $source, $this->watchList($name, $watch), $entity, $type);

        return $this;
    }

    /**
     * @param array<array-key, mixed> $watch
     *
     * @return list<Watch>
     */
    private function watchList(string $source, array $watch): array
    {
        $list = [];
        foreach ($watch as $key => $entry) {
            if ($entry instanceof Watch) {
                $list[] = $entry;
            } elseif (is_string($key) && is_string($entry)) {
                $list[] = new Watch($key, $entry);
            } elseif (is_string($key) && is_array($entry)) {
                $columns = $entry['columns'] ?? null;
                if ($columns !== null && (!is_array($columns) || array_filter($columns, 'is_string') !== $columns)) {
                    $this->sourceProblems[] = sprintf('Source "%s": watch on "%s": "columns" must be a list of strings.', $source, $key);
                    $columns = null;
                }
                $list[] = new Watch($key, is_string($entry['ids'] ?? null) ? $entry['ids'] : 'SELECT :id', is_string($entry['key'] ?? null) ? $entry['key'] : 'id', $columns === null || $columns === [] ? null : array_values($columns));
            } else {
                $this->sourceProblems[] = sprintf('Source "%s": a watch is a Watch object or table => "SELECT ... :id" / table => { ids, key, columns }.', $source);
            }
        }

        return $list;
    }

    /** @param ManyValues|array<array-key, mixed>|null $many the values come from a child table: ManyValues, or ['from' => ..., 'key' => ..., 'column' => ..., 'limit' => 50] */
    public function field(string $name, Weight|string $weight = Weight::B, bool $fuzzy = false, bool $highlight = true, ?string $column = null, ?string $granted = null, bool $code = false, ManyValues|array|null $many = null): self
    {
        $weight = $weight instanceof Weight ? $weight : EnumOption::parse(Weight::class, strtoupper($weight), $this->name, 'weight', sprintf(' of field "%s"', $name));
        $this->fields[] = new FieldDefinition($name, $weight, $fuzzy, $highlight, $column, $granted, $code, is_array($many) ? ManyValues::fromArray($this->name, sprintf('Field "%s"', $name), $many) : $many);

        return $this;
    }

    /** @param ManyValues|array<array-key, mixed>|null $many see field() */
    public function filter(string $name, FilterType|string $type, ?string $column = null, ManyValues|array|null $many = null): self
    {
        $type = $type instanceof FilterType ? $type : EnumOption::parse(FilterType::class, $type, $this->name, 'filter type', sprintf(' of filter "%s"', $name));
        $this->filters[] = new FilterDefinition($name, $type, $column, is_array($many) ? ManyValues::fromArray($this->name, sprintf('Filter "%s"', $name), $many) : $many);

        return $this;
    }

    /**
     * Re-index the documents affected by a change in $table. $affectedIds is a SELECT returning the
     * affected document ids, with ":id" standing for the changed row's $keyColumn value.
     *
     * The SQL is trusted developer input and is embedded verbatim in generated trigger functions:
     * never build it from user input. It must not contain the "$fuzzphony$" dollar-quote tag.
     *
     * @param list<string>|null $columns
     */
    public function watch(string $table, string $affectedIds = 'SELECT :id', string $keyColumn = 'id', ?array $columns = null): self
    {
        // Normalize [] to null at the source, so Watch::$columns is never a distinguishable-
        // but-equivalent [] — every downstream consumer already treats them the same anyway.
        $this->watches[] = new Watch($table, $affectedIds, $keyColumn, $columns !== [] ? $columns : null);

        return $this;
    }

    public function idType(IdType|string $type): self
    {
        $this->idType = $type instanceof IdType ? $type : EnumOption::parse(IdType::class, $type, $this->name, 'id type');

        return $this;
    }

    public function sync(SyncMode|string $mode): self
    {
        $this->sync = $mode instanceof SyncMode ? $mode : EnumOption::parse(SyncMode::class, $mode, $this->name, 'sync mode');

        return $this;
    }

    public function triggerLevel(TriggerLevel|string $level): self
    {
        $this->triggerLevel = $level instanceof TriggerLevel ? $level : EnumOption::parse(TriggerLevel::class, $level, $this->name, 'trigger level');

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

    /**
     * Query-side synonyms: groups (`['tv', 'television']`) and one-way rules (`'laptop => notebook'`).
     *
     * @param array<array-key, mixed> $entries
     */
    public function synonyms(array $entries): self
    {
        $this->synonyms = Synonyms::fromEntries($entries, $this->name);

        return $this;
    }

    /** Query-side synonyms from a file in the Solr format (`tv, television` / `laptop => notebook`), read now. */
    public function synonymsFile(string $path): self
    {
        $this->synonyms = Synonyms::fromFile($path, $this->name);

        return $this;
    }

    /** Whether searches on this index go to the search log (the default); log(false) keeps one out of it. */
    public function log(bool $log = true): self
    {
        $this->log = $log;

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
        if ($this->sources !== []) {
            return $this->buildMultiSource();
        }
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
            synonyms: $this->synonyms,
            log: $this->log,
        );
        DefinitionValidator::assertValid($definition);

        return $definition;
    }

    private function buildMultiSource(): IndexDefinition
    {
        $problems = $this->sourceProblems;
        if ($this->source !== null) {
            $problems[] = 'An index has either one source (fromTable / fromQuery) or several (source / sourceFromTable), not both.';
        }
        if ($this->watches !== []) {
            $problems[] = 'An index with sources declares its watches inside each source (source(..., watch: [...])), not on the index.';
        }
        if ($this->entityClass !== null) {
            $problems[] = 'An index with sources declares the entity class inside each source (source(..., entity: Product::class)), not on the index.';
        }
        if ($this->idType !== IdType::Int) {
            $problems[] = 'An index with sources declares the id type inside each source (source(..., idType: ...)), not on the index.';
        }
        if ($problems !== []) {
            throw new InvalidDefinition($this->name, $problems);
        }
        $definition = new IndexDefinition(
            name: $this->name,
            source: $this->sources[0]->source,
            fields: $this->fields,
            filters: $this->filters,
            idType: IdType::String,
            sync: $this->sync,
            text: $this->text,
            boostColumn: $this->boost,
            recencyColumn: $this->recency,
            profiles: $this->profiles + ['default' => new RankingProfile()],
            thresholds: $this->thresholds,
            triggerLevel: $this->triggerLevel,
            tenant: $this->tenant,
            synonyms: $this->synonyms,
            log: $this->log,
            sources: $this->sources,
        );
        DefinitionValidator::assertValid($definition);

        return $definition;
    }
}
