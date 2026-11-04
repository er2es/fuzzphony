<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexDefinitionClass;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\ArticleAdminIndex;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\LegacyOrdersIndex;
use Fuzzphony\Tests\Fixtures\NoAttributeClass;
use Fuzzphony\Tests\Fixtures\NotADefinitionClass;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionClassTest extends TestCase
{
    public function testTheBuilderArrivesWithTheNameAndTheEntity(): void
    {
        $definition = IndexDefinitionClass::fromClass(ArticleAdminIndex::class);

        self::assertSame('articles_admin', $definition->name);
        self::assertSame(Article::class, $definition->entityClass);
        self::assertSame(['title', 'body'], $definition->fieldNames());
    }

    public function testALegacyTableNeedsNoEntity(): void
    {
        $definition = IndexDefinitionClass::fromClass(LegacyOrdersIndex::class);

        self::assertSame('legacy_orders', $definition->name);
        self::assertNull($definition->entityClass);
        self::assertSame('legacy_order', $definition->source->table);
    }

    public function testBuildingTwiceYieldsEqualDefinitionsWithTheSameFingerprint(): void
    {
        $first = IndexDefinitionClass::fromClass(ArticleAdminIndex::class);
        $second = IndexDefinitionClass::fromInstance(new ArticleAdminIndex());

        self::assertEquals($first, $second);
        self::assertSame(Fingerprint::definition($first), Fingerprint::definition($second));
    }

    public function testADefinitionSurvivesTheCacheRoundTripEqual(): void
    {
        $fresh = IndexDefinitionClass::fromClass(ArticleAdminIndex::class);

        self::assertEquals($fresh, unserialize(serialize($fresh)));
    }

    public function testAClassWithoutTheAttributeIsRefused(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('has no #[AsIndexDefinition] attribute');
        IndexDefinitionClass::fromClass(NoAttributeClass::class);
    }

    public function testAClassWithoutBuildIsRefused(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('has no build(IndexBuilder $builder): void method');
        IndexDefinitionClass::fromClass(NotADefinitionClass::class);
    }

    public function testAnUnknownClassIsRefused(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('is not a class that can be loaded');
        IndexDefinitionClass::fromClass('App\Missing\Index'); // @phpstan-ignore argument.type
    }

    public function testTwoIndexesOverOneEntityTheFirstAnswersForTheClass(): void
    {
        $registry = new IndexRegistry([IndexDefinition::builder('articles')->fromTable('fz_article')->field('title', 'A')->entity(Article::class)->build()]);
        $registry->register(IndexDefinitionClass::fromClass(ArticleAdminIndex::class), 'definition class ' . ArticleAdminIndex::class);

        self::assertSame('articles', $registry->get(Article::class)->name);
        self::assertSame('articles_admin', $registry->get('articles_admin')->name);
        self::assertSame(Article::class, $registry->get('articles_admin')->entityClass);
        self::assertSame('definition class ' . ArticleAdminIndex::class, $registry->origin('articles_admin'));
        self::assertNull($registry->origin('articles'));
    }

    public function testTheSameNameFromTwoSourcesNamesBoth(): void
    {
        $registry = new IndexRegistry([IndexDefinition::builder('articles_admin')->fromTable('fz_article')->field('title', 'A')->build()]);
        $registry->annotateOrigin('articles_admin', 'attribute Some\Entity');

        try {
            $registry->register(IndexDefinitionClass::fromClass(ArticleAdminIndex::class), 'definition class ' . ArticleAdminIndex::class);
            self::fail('a duplicate name must be refused');
        } catch (InvalidDefinition $e) {
            self::assertSame('articles_admin', $e->index);
            self::assertStringContainsString('defined twice: by attribute Some\Entity and by definition class ' . ArticleAdminIndex::class, $e->getMessage());
        }
    }

    public function testTheMessageOfAnUnattributedEarlierDefinition(): void
    {
        $registry = new IndexRegistry([Indexes::products()]);

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('by an earlier definition and by x');
        $registry->register(Indexes::products(), 'x');
    }

    public function testAnOverrideWithoutAnOriginStillReplaces(): void
    {
        $registry = new IndexRegistry([Indexes::products()]);
        $registry->register(Indexes::products()->withLog(false));

        self::assertFalse($registry->get('products')->log);
    }
}
