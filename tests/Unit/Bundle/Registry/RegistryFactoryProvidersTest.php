<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Registry;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Registry\RegistryCacheWarmer;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Core\Definition\DefinitionProvider;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Tests\Fixtures\LegacyOrdersIndex;
use Fuzzphony\Tests\Fixtures\RowsDefinitionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;

/** DefinitionProvider in the registry: order, origin, collisions with every other source, validation, called once per build, never cached. */
final class RegistryFactoryProvidersTest extends TestCase
{
    public function testTheSourcesComposeInOrderAndEachIndexKnowsItsOrigin(): void
    {
        $registry = RegistryFactory::create(
            ['from_yaml' => ['source' => ['table' => 'x'], 'fields' => ['name' => 'A']]],
            ...$this->withAttributeIndex('from_attribute'),
            definitionClasses: [new LegacyOrdersIndex()],
            providers: [self::provider('from_provider')],
        );

        self::assertSame(['from_attribute', 'legacy_orders', 'from_provider', 'from_yaml'], array_keys($registry->all()));
        self::assertSame('provider ' . RowsDefinitionProvider::class, $registry->origin('from_provider'));
        self::assertSame(['title'], $registry->get('from_provider')->fieldNames());
    }

    /** @return iterable<string, array{array<string, array<string, mixed>>, bool, list<object>, list<DefinitionProvider>, string}> */
    public static function collisions(): iterable
    {
        $provider = 'provider ' . RowsDefinitionProvider::class;
        yield 'attribute' => [[], true, [], [self::provider('legacy_orders')], 'by attribute #[Searchable] and by ' . $provider];
        yield 'definition class' => [[], false, [new LegacyOrdersIndex()], [self::provider('legacy_orders')], 'by definition class ' . LegacyOrdersIndex::class . ' and by ' . $provider];
        yield 'another provider' => [[], false, [], [self::provider('legacy_orders'), self::provider('legacy_orders')], 'by ' . $provider . ' and by ' . $provider];
        yield 'the same provider twice' => [[], false, [], [self::provider('legacy_orders', 'legacy_orders')], 'by ' . $provider . ' and by ' . $provider];
        yield 'YAML' => [['legacy_orders' => ['weights' => ['title' => 'B']]], false, [], [self::provider('legacy_orders')], 'by ' . $provider . ' and by YAML'];
    }

    /**
     * @param array<string, array<string, mixed>> $yaml
     * @param list<object>                        $classes
     * @param list<DefinitionProvider>            $providers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('collisions')]
    public function testANameAProviderDefinesMayNotBeDefinedByAnyOtherSource(array $yaml, bool $attribute, array $classes, array $providers, string $sources): void
    {
        try {
            RegistryFactory::create($yaml, ...($attribute ? self::withAttributeIndex('legacy_orders') : []), definitionClasses: $classes, providers: $providers);
            self::fail('a name defined twice must be refused');
        } catch (InvalidDefinition $e) {
            self::assertSame('legacy_orders', $e->index);
            self::assertStringContainsString('the index is defined twice: ' . $sources . '.', $e->getMessage());
        }
    }

    public function testAProviderDefinitionIsValidatedLikeAnyOther(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/SELECT|WITH/');

        RegistryFactory::create([], providers: [new RowsDefinitionProvider([['name' => 'bad', 'query' => 'DELETE FROM t', 'fields' => '{"title": "A"}']])]);
    }

    public function testAGrantedFieldFromAProviderNeedsTheAuthorizationGuard(): void
    {
        $granted = new class implements DefinitionProvider {
            public function definitions(): iterable
            {
                yield IndexDefinition::builder('secret_docs')->fromTable('doc')->field('title', 'A')->field('salary', 'C', granted: 'ROLE_HR')->build();
            }
        };

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage(RegistryFactory::GRANTED_WITHOUT_AUTHORIZATION);

        RegistryFactory::create([], authorizationEnabled: false, providers: [$granted]);
    }

    public function testAProviderIsCalledOncePerBuildNeverCachedAndNeverByTheWarmer(): void
    {
        $calls = 0;
        $provider = new class ($calls) implements DefinitionProvider {
            public function __construct(private int &$calls) {}

            public function definitions(): iterable
            {
                ++$this->calls;

                return (new RowsDefinitionProvider([['name' => 'p', 'query' => 'SELECT id, title FROM p', 'fields' => '{"title": "A"}']]))->definitions();
            }
        };
        $cache = new ArrayAdapter();

        RegistryFactory::create([], null, $cache, true, definitionClasses: [new LegacyOrdersIndex()], providers: [$provider]);
        self::assertSame(1, $calls);
        $second = RegistryFactory::create([], null, $cache, true, definitionClasses: [new LegacyOrdersIndex()], providers: [$provider]);
        self::assertSame(2, $calls, 'a warm cache still asks the provider: its definitions are not cached');
        self::assertTrue($second->has('p'));
        $cached = $cache->getItem('fuzzphony_discovered_definition_classes')->get();
        self::assertIsArray($cached);
        self::assertSame([LegacyOrdersIndex::class], array_keys($cached), 'nothing of the provider lands in the cache');
        self::assertCount(1, $cache->getValues());

        (new RegistryCacheWarmer([], null, $cache, null, true, [new LegacyOrdersIndex()]))->warmUp('unused');
        self::assertSame(2, $calls, 'cache:warmup never asks a provider (a build host has no database)');
    }

    public function testTheDoctorNamesTheProviderOfAnIndex(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('inspect')->willReturnCallback(static fn(IndexDefinition $index): InspectionReport => new InspectionReport($index->name, [Check::ok('Table', 'fine')]));
        $tester = new CommandTester(new DoctorCommand(new Fuzzphony($engine, RegistryFactory::create([], providers: [self::provider('from_db')]))));

        $tester->execute([], ['interactive' => false]);

        self::assertStringContainsString('Index "from_db" (provider ' . RowsDefinitionProvider::class . ')', $tester->getDisplay(true));
    }

    private static function provider(string ...$names): RowsDefinitionProvider
    {
        return new RowsDefinitionProvider(array_map(static fn(string $n): array => ['name' => $n, 'query' => 'SELECT id, title FROM t', 'fields' => '{"title": "A"}'], $names));
    }

    /**
     * A discovery whose warm cache holds one #[Searchable] index (no entity manager is ever asked).
     *
     * @return array{discovery: DoctrineIndexDiscovery, cache: ArrayAdapter, cacheInCli: true}
     */
    private function withAttributeIndex(string $name): array
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('fuzzphony_discovered_indexes')->set([IndexDefinition::builder($name)->fromTable('t')->field('title', 'A')->build()]));
        return ['discovery' => new DoctrineIndexDiscovery(self::createStub(EntityManagerInterface::class)), 'cache' => $cache, 'cacheInCli' => true];
    }
}
