<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\DefinitionValidator;
use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class DefinitionValidatorTest extends TestCase
{
    public function testAValidDefinitionHasNoViolations(): void
    {
        self::assertSame([], DefinitionValidator::validate(Indexes::products()));
    }

    public function testAllProblemsAreReportedTogether(): void
    {
        $definition = new IndexDefinition(
            name: 'Bad-Name',
            source: Source::table('product; DROP TABLE x'),
            fields: [new FieldDefinition('name'), new FieldDefinition('name')],
            profiles: ['default' => new RankingProfile(boost: 1.0)],
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(4, $violations, implode("\n", $violations));
        self::assertStringContainsString('Index name "Bad-Name"', $violations[0]);
        self::assertStringContainsString('not a valid identifier', $violations[1]);
        self::assertStringContainsString('defined twice', $violations[2]);
        self::assertStringContainsString('boostBy', $violations[3]);
    }

    public function testQuerySourcesNeedWatchesForTriggerSync(): void
    {
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessageMatches('/cannot be watched automatically.*watch\("brand"/s');

        IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name FROM product p')
            ->field('name', 'A')
            ->sync(SyncMode::Queue)
            ->build();
    }

    public function testManualSyncDoesNotNeedWatches(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name FROM product p')
            ->field('name', 'A')
            ->sync('manual')
            ->build();

        self::assertSame(SyncMode::Manual, $definition->sync);
    }

    public function testWatchMustReferenceIdExactlyOnce(): void
    {
        $definition = Indexes::products()->with(watches: [new Watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :ids')]);

        self::assertStringContainsString('":id" exactly once', DefinitionValidator::validate($definition)[0]);
    }

    public function testTableSourcesWatchThemselves(): void
    {
        $definition = IndexDefinition::builder('products')->fromTable('product')->field('name')->build();

        self::assertSame('product', $definition->effectiveWatches()[0]->table);
        self::assertSame('SELECT :id', $definition->effectiveWatches()[0]->affectedIds);
    }

    public function testBuilderCanDeclareATenantScope(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->filter('account_id', 'int')
            ->tenant('account_id')
            ->build();

        self::assertSame('account_id', $definition->tenant);
    }

    public function testTenantMustReferenceADeclaredFilter(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            tenant: 'account_id',
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations);
        self::assertStringContainsString('tenant("account_id")', $violations[0]);
    }

    public function testTenantReferencingADeclaredFilterIsValid(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->filter('account_id', 'int')
            ->tenant('account_id')
            ->build(); // build() calls DefinitionValidator::assertValid() internally; it must not throw

        self::assertSame([], DefinitionValidator::validate($definition));
    }

    public function testBuilderCanDeclareWatchColumns(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromTable('product')
            ->field('name')
            ->watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['name'])
            ->build();

        self::assertSame(['name'], $definition->watches[0]->columns);
    }

    public function testWatchColumnsMustBeValidColumnNames(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            watches: [new Watch('brand', 'SELECT id FROM product WHERE brand_id = :id', columns: ['not a column!'])],
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations);
        self::assertStringContainsString('column "not a column!"', $violations[0]);
    }

    public function testSourceQueryMustNotContainTheDollarQuoteTag(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::query('SELECT id, name FROM product WHERE name <> $fuzzphony$x$fuzzphony$'),
            fields: [new FieldDefinition('name')],
            sync: SyncMode::Manual,
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringContainsString('Source query must not contain "$fuzzphony$"', $violations[0]);
    }

    public function testWatchAffectedIdsMustNotContainTheDollarQuoteTag(): void
    {
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::table('product'),
            fields: [new FieldDefinition('name')],
            watches: [new Watch('brand', 'SELECT id FROM product WHERE brand_id = :id; END $fuzzphony$; DROP TABLE x; --')],
        );

        $violations = DefinitionValidator::validate($definition);

        self::assertCount(1, $violations, implode("\n", $violations));
        self::assertStringContainsString('Watch on "brand": affectedIds must not contain "$fuzzphony$"', $violations[0]);
    }

    public function testTheDollarQuoteTagIsMatchedCaseSensitivelyLikePostgres(): void
    {
        // PostgreSQL dollar-quote tags are case sensitive: $FUZZPHONY$ does not end a $fuzzphony$ body.
        $definition = new IndexDefinition(
            name: 'products',
            source: Source::query('SELECT id, $FUZZPHONY$x$FUZZPHONY$ AS name FROM product'),
            fields: [new FieldDefinition('name')],
            sync: SyncMode::Manual,
        );

        self::assertSame([], DefinitionValidator::validate($definition));
    }
}
