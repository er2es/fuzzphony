<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Wizard\Export\AttributeExporter;
use Fuzzphony\Core\Wizard\Export\BuilderExporter;
use Fuzzphony\Core\Wizard\Export\YamlExporter;

/**
 * The configuration wizard as a library call (Fuzzphony::wizard()): reads the structure and the statistics of a table and
 * suggests an index definition with the reason of every decision, and prints a definition as YAML, builder code or attributes.
 */
final readonly class Wizard
{
    /** @internal */
    public function __construct(private SourceIntrospector $introspector) {}

    /** @return list<array{table: string, rows: int}> the user tables, largest first */
    public function tables(): array
    {
        return $this->introspector->tables();
    }

    /**
     * @throws \InvalidArgumentException when the table does not exist
     */
    public function suggest(string $table, ?string $indexName = null, string $language = 'english'): Suggestion
    {
        return (new DefinitionSuggester())->suggest($this->introspector->describe($table), $indexName, $language);
    }

    public function yaml(IndexDefinition $definition): string
    {
        return (new YamlExporter())->export($definition);
    }

    public function builder(IndexDefinition $definition): string
    {
        return (new BuilderExporter())->export($definition);
    }

    /** The definition as `#[Searchable]` attributes on an entity class, or null when attributes cannot express it (a joined source, extra watches, ranking profiles or thresholds). */
    public function attributes(IndexDefinition $definition, string $className = 'Product'): ?string
    {
        $exporter = new AttributeExporter();

        return $exporter->supports($definition) ? $exporter->export($definition, $className) : null;
    }
}
