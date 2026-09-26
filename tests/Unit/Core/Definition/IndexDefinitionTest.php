<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexDefinition): IndexDefinition, string, mixed}> */
    public static function withers(): iterable
    {
        yield 'name' => [static fn(IndexDefinition $d): IndexDefinition => $d->withName('other'), 'name', 'other'];
        yield 'source' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSource(Source::table('fz_other')), 'source', Source::table('fz_other')];
        yield 'fields' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::A)]), 'fields', [new FieldDefinition('name', Weight::A)]];
        yield 'filters' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFilters([new FilterDefinition('price', FilterType::Float)]), 'filters', [new FilterDefinition('price', FilterType::Float)]];
        yield 'watches' => [static fn(IndexDefinition $d): IndexDefinition => $d->withWatches([new Watch('fz_product')]), 'watches', [new Watch('fz_product')]];
        yield 'id type' => [static fn(IndexDefinition $d): IndexDefinition => $d->withIdType(IdType::Uuid), 'idType', IdType::Uuid];
        yield 'sync' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSync(SyncMode::Manual), 'sync', SyncMode::Manual];
        yield 'text' => [static fn(IndexDefinition $d): IndexDefinition => $d->withText(new TextConfig('german', false)), 'text', new TextConfig('german', false)];
        yield 'boost column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn('rank'), 'boostColumn', 'rank'];
        yield 'no boost column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn(null), 'boostColumn', null];
        yield 'recency column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn('updated_at'), 'recencyColumn', 'updated_at'];
        yield 'no recency column' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn(null), 'recencyColumn', null];
        yield 'profiles' => [static fn(IndexDefinition $d): IndexDefinition => $d->withProfiles(['default' => new RankingProfile(text: 0.5)]), 'profiles', ['default' => new RankingProfile(text: 0.5)]];
        yield 'thresholds' => [static fn(IndexDefinition $d): IndexDefinition => $d->withThresholds(new Thresholds(minScore: 0.2)), 'thresholds', new Thresholds(minScore: 0.2)];
        yield 'entity class' => [static fn(IndexDefinition $d): IndexDefinition => $d->withEntityClass(Product::class), 'entityClass', Product::class];
        yield 'trigger level' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTriggerLevel(TriggerLevel::Row), 'triggerLevel', TriggerLevel::Row];
        yield 'tenant' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTenant('brand_id'), 'tenant', 'brand_id'];
    }

    /** @param \Closure(IndexDefinition): IndexDefinition $wither */
    #[DataProvider('withers')]
    public function testEachWitherReplacesOnlyItsOwnProperty(\Closure $wither, string $property, mixed $expected): void
    {
        $original = Indexes::products();

        $changed = $wither($original);

        self::assertNotSame($original, $changed);
        self::assertEquals($expected, get_object_vars($changed)[$property]);
        self::assertSame(
            array_diff_key(get_object_vars($original), [$property => true]),
            array_diff_key(get_object_vars($changed), [$property => true]),
            'every other property is the very same value',
        );
    }

    public function testNullableWithersCanClearAValue(): void
    {
        $withEntity = Indexes::products()->withEntityClass(Product::class)->withTenant('brand_id');

        self::assertNull($withEntity->withEntityClass(null)->entityClass);
        self::assertNull($withEntity->withTenant(null)->tenant);
    }
}
