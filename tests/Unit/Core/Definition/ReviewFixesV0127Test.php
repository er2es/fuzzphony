<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ReviewFixesV0127Test extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reserved(): iterable
    {
        foreach (DefinitionValidator::RESERVED_NAMES as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('reserved')]
    public function testAnIndexNamedLikeASharedObjectIsRejected(string $name): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('reserved');
        IndexDefinition::builder($name)->fromTable('product')->field('name', 'A')->build();
    }

    public function testTheReservedNamesCoverEverySharedObjectOfTheNamesClass(): void
    {
        $names = new Names('public');
        foreach ([$names->queue(), $names->watchLog(), $names->watches(), $names->meta(), $names->searchLog()] as $shared) {
            $bare = substr((string) strrchr('.' . str_replace('"', '', $shared), '.'), 1);
            self::assertContains(substr($bare, strlen('fuzzphony_')), DefinitionValidator::RESERVED_NAMES, $shared);
        }
        self::assertContains(substr($names->queueOrderIndex(), 10), DefinitionValidator::RESERVED_NAMES);
        self::assertContains(substr($names->watchLogIndexName(), 10), DefinitionValidator::RESERVED_NAMES);
        self::assertContains(substr($names->searchLogIndexName('index_at'), 10), DefinitionValidator::RESERVED_NAMES);
    }

    public function testASourceNameWithTwoUnderscoresIsRejected(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('"__"');

        IndexDefinition::builder('site')->sourceFromTable('a__b', 'product')->field('name', 'A')->build();
    }

    public function testIdentifiersWithATrailingNewlineAreRejected(): void
    {
        self::assertFalse(Identifier::isName("products\n"));
        self::assertFalse(Identifier::isColumn("name\n"));
        self::assertFalse(Identifier::isTable("product\n"));
        self::assertFalse(Identifier::isTable("public.product\n"));
        self::assertTrue(Identifier::isTable('public.product'));
    }

    public function testTwoAttributeEntitiesWithOneIndexNameAreAnErrorNamingBoth(): void
    {
        $cache = new ArrayAdapter();
        $a = IndexDefinition::builder('items')->fromTable('p')->field('name', 'A')->entity(\DateTime::class)->build();
        $b = IndexDefinition::builder('items')->fromTable('n')->field('name', 'A')->entity(\ArrayObject::class)->build();
        $item = $cache->getItem('fuzzphony_discovered_indexes');
        $cache->save($item->set([$a, $b]));
        $discovery = new DoctrineIndexDiscovery(self::createStub(EntityManagerInterface::class));

        try {
            RegistryFactory::create([], $discovery, $cache, cacheInCli: true);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString(\DateTime::class, $e->getMessage());
            self::assertStringContainsString(\ArrayObject::class, $e->getMessage());
        }
    }

    public function testTheSearchComponentEvaluatesTheChargedResultBeforeTheCompletions(): void
    {
        $twig = (string) file_get_contents(__DIR__ . '/../../../../src/Bundle/templates/components/Search.html.twig');

        self::assertLessThan(strpos($twig, 'this.completions'), strpos($twig, 'this.result'));
    }
}
