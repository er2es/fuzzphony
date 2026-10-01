<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The public API is an explicit list (docs/architecture.md#public-api); every other class,
 * interface and enum in src/ is @internal. From 1.0 the BC promise covers exactly this list.
 */
final class PublicApiTest extends TestCase
{
    private const array PUBLIC = [
        \Fuzzphony\Bridge\Doctrine\DbalConnection::class,
        \Fuzzphony\Bridge\Doctrine\EntityLoader::class,
        \Fuzzphony\Bridge\Doctrine\OrmSyncListener::class,
        \Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter::class,
        \Fuzzphony\Bundle\FuzzphonyBundle::class,
        \Fuzzphony\Bundle\Messenger\RefreshDocuments::class,
        \Fuzzphony\Bundle\Twig\SearchComponent::class,
        \Fuzzphony\Core\Attribute\SearchField::class,
        \Fuzzphony\Core\Attribute\SearchFilter::class,
        \Fuzzphony\Core\Attribute\Searchable::class,
        \Fuzzphony\Core\Database\Connection::class,
        \Fuzzphony\Core\Database\PdoConnection::class,
        \Fuzzphony\Core\Database\TransactionAware::class,
        \Fuzzphony\Core\Definition\FieldDefinition::class,
        \Fuzzphony\Core\Definition\FilterDefinition::class,
        \Fuzzphony\Core\Definition\FilterType::class,
        \Fuzzphony\Core\Definition\IdType::class,
        \Fuzzphony\Core\Definition\IndexBuilder::class,
        \Fuzzphony\Core\Definition\IndexDefinition::class,
        \Fuzzphony\Core\Definition\Source::class,
        \Fuzzphony\Core\Definition\SyncMode::class,
        \Fuzzphony\Core\Definition\TextConfig::class,
        \Fuzzphony\Core\Definition\TriggerLevel::class,
        \Fuzzphony\Core\Definition\Watch::class,
        \Fuzzphony\Core\Definition\Weight::class,
        \Fuzzphony\Core\Engine\Capabilities::class,
        \Fuzzphony\Core\Engine\Capability::class,
        \Fuzzphony\Core\Engine\Engine::class,
        \Fuzzphony\Core\Exception\EngineFailure::class,
        \Fuzzphony\Core\Exception\FuzzphonyException::class,
        \Fuzzphony\Core\Exception\InvalidArgument::class,
        \Fuzzphony\Core\Exception\InvalidConfiguration::class,
        \Fuzzphony\Core\Exception\InvalidDefinition::class,
        \Fuzzphony\Core\Exception\InvalidQuery::class,
        \Fuzzphony\Core\Exception\RebuildAlreadyRunning::class,
        \Fuzzphony\Core\Exception\UnknownIndex::class,
        \Fuzzphony\Core\Fuzzphony::class,
        \Fuzzphony\Core\Inspection\Check::class,
        \Fuzzphony\Core\Inspection\CheckStatus::class,
        \Fuzzphony\Core\Inspection\InspectOptions::class,
        \Fuzzphony\Core\Inspection\InspectionReport::class,
        \Fuzzphony\Core\Observability\LoggingMetricsCollector::class,
        \Fuzzphony\Core\Observability\MetricsCollector::class,
        \Fuzzphony\Core\Observability\NullMetricsCollector::class,
        \Fuzzphony\Core\Query\Filter\Condition::class,
        \Fuzzphony\Core\Query\Filter\Operator::class,
        \Fuzzphony\Core\Query\SearchQuery::class,
        \Fuzzphony\Core\Ranking\FuzzyMode::class,
        \Fuzzphony\Core\Ranking\RankingProfile::class,
        \Fuzzphony\Core\Ranking\Thresholds::class,
        \Fuzzphony\Core\Registry\IndexRegistry::class,
        \Fuzzphony\Core\Schema\SchemaPlan::class,
        \Fuzzphony\Core\Schema\Statement::class,
        \Fuzzphony\Core\Search\Explanation::class,
        \Fuzzphony\Core\Search\Hit::class,
        \Fuzzphony\Core\Search\ScoreBreakdown::class,
        \Fuzzphony\Core\Search\SearchBuilder::class,
        \Fuzzphony\Core\Search\SearchResult::class,
        \Fuzzphony\Core\Sync\ImmediateRefreshDispatcher::class,
        \Fuzzphony\Core\Sync\RefreshDispatcher::class,
        \Fuzzphony\Core\Sync\ReindexOptions::class,
        \Fuzzphony\Core\Sync\ReindexResult::class,
        \Fuzzphony\Core\Wizard\ColumnKind::class,
        \Fuzzphony\Core\Wizard\ColumnProfile::class,
        \Fuzzphony\Core\Wizard\Decision::class,
        \Fuzzphony\Core\Wizard\DefinitionSuggester::class,
        \Fuzzphony\Core\Wizard\Export\AttributeExporter::class,
        \Fuzzphony\Core\Wizard\Export\BuilderExporter::class,
        \Fuzzphony\Core\Wizard\Export\YamlExporter::class,
        \Fuzzphony\Core\Wizard\ForeignKey::class,
        \Fuzzphony\Core\Wizard\SourceIntrospector::class,
        \Fuzzphony\Core\Wizard\Suggestion::class,
        \Fuzzphony\Core\Wizard\TableProfile::class,
        \Fuzzphony\Engine\Postgres\PostgresEngine::class,
    ];

    /** Source directory => namespace, as in composer.json's autoload. */
    private const array PREFIXES = [
        'Core/' => 'Fuzzphony\\Core\\',
        'Engine/Postgres/' => 'Fuzzphony\\Engine\\Postgres\\',
        'Bridge/Doctrine/' => 'Fuzzphony\\Bridge\\Doctrine\\',
        'Bundle/' => 'Fuzzphony\\Bundle\\',
    ];

    public function testEveryClassIsEitherPublicApiOrInternal(): void
    {
        $wrong = [];
        foreach (self::classes() as $class) {
            self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $class . ' does not autoload');
            $internal = str_contains((string) (new \ReflectionClass($class))->getDocComment(), '@internal');
            $public = in_array($class, self::PUBLIC, true);
            if ($internal === $public) {
                $wrong[] = sprintf('%s: %s', $class, $public ? 'public API, but tagged @internal' : 'neither public API nor @internal');
            }
        }

        self::assertSame([], $wrong);
        self::assertCount(74, self::PUBLIC);
        self::assertCount(128, self::classes());
    }

    public function testThePublicApiExposesNoInternalType(): void
    {
        $leaks = [];
        foreach (self::PUBLIC as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getDeclaringClass()->getName(), 'Fuzzphony\\') || self::isInternal($method)) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $leaks = [...$leaks, ...self::internalTypes($parameter->getType(), sprintf('%s::%s() $%s', $class, $method->getName(), $parameter->getName()))];
                }
                $leaks = [...$leaks, ...self::internalTypes($method->getReturnType(), sprintf('%s::%s() return', $class, $method->getName()))];
            }
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (str_starts_with($property->getDeclaringClass()->getName(), 'Fuzzphony\\') && !self::isInternal($property)) {
                    $leaks = [...$leaks, ...self::internalTypes($property->getType(), sprintf('%s::$%s', $class, $property->getName()))];
                }
            }
        }

        self::assertSame([], $leaks);
    }

    public function testTheLeakCheckSeesInternalTypes(): void
    {
        $method = new \ReflectionMethod(\Fuzzphony\Core\Fuzzphony::class, 'engine');

        self::assertSame([], self::internalTypes($method->getReturnType(), 'engine'), 'Engine is public API');
        self::assertSame(
            ['x: Fuzzphony\Core\Support\Coerce'],
            self::internalTypes((new \ReflectionFunction(static fn(\Fuzzphony\Core\Support\Coerce|int|null $c): null => null))->getParameters()[0]->getType(), 'x'),
        );
    }

    /** @return list<string> */
    private static function internalTypes(?\ReflectionType $type, string $where): array
    {
        $named = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType, $type instanceof \ReflectionIntersectionType => $type->getTypes(),
            default => [],
        };
        $leaks = [];
        foreach ($named as $single) {
            if (!$single instanceof \ReflectionNamedType || $single->isBuiltin()) {
                continue;
            }
            /** @var class-string $name a non-builtin named type */
            $name = $single->getName();
            if (str_starts_with($name, 'Fuzzphony\\') && self::isInternal(new \ReflectionClass($name))) {
                $leaks[] = sprintf('%s: %s', $where, $name);
            }
        }

        return $leaks;
    }

    /** @param \ReflectionClass<object>|\ReflectionMethod|\ReflectionProperty $reflection */
    private static function isInternal(\ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflection): bool
    {
        return str_contains((string) $reflection->getDocComment(), '@internal');
    }

    public function testTheArchitectureDocListsExactlyThePublicApi(): void
    {
        $docs = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/architecture.md');
        $start = strpos($docs, "\n## Public API\n");
        self::assertNotFalse($start, 'docs/architecture.md has a "## Public API" section');
        $end = strpos($docs, "\n## ", $start + 1);
        $section = $end === false ? substr($docs, $start) : substr($docs, $start, $end - $start);

        foreach (self::PUBLIC as $class) {
            self::assertStringContainsString('`' . $class . '`', $section, $class . ' is missing from the Public API section');
        }
        self::assertSame(count(self::PUBLIC), substr_count($section, '`Fuzzphony\\'), 'the section lists nothing else');
    }

    public function testTheDemoAndTheBenchmarkUseOnlyThePublicApi(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/benchmarks/run.php'];
        foreach (['/demo/src/*/*.php', '/demo/src/*/*/*.php'] as $pattern) {
            $found = glob($root . $pattern);
            array_push($files, ...($found !== false ? $found : []));
        }
        $used = [];
        foreach ($files as $file) {
            preg_match_all('/^use (Fuzzphony\\\\[A-Za-z\\\\]+);/m', (string) file_get_contents($file), $matches);
            array_push($used, ...$matches[1]);
        }

        self::assertNotSame([], $used);
        self::assertSame([], array_values(array_diff(array_unique($used), self::PUBLIC)), 'internal classes used by the demo or the benchmark');
    }

    /** @return list<string> */
    private static function classes(): array
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)), -4);
            foreach (self::PREFIXES as $directory => $namespace) {
                if (str_starts_with($relative, $directory)) {
                    $classes[] = $namespace . str_replace('/', '\\', substr($relative, strlen($directory)));
                }
            }
        }
        sort($classes);

        return $classes;
    }
}
