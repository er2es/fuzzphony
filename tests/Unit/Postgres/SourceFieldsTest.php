<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Engine\Postgres\SearchSession;
use Fuzzphony\Engine\Postgres\SourceFields;
use PHPUnit\Framework\TestCase;

/** The returnable columns of a page: which names resolve, and the one statement per source (no database needed). */
final class SourceFieldsTest extends TestCase
{
    private static function index(): IndexDefinition
    {
        return IndexDefinition::builder('items')
            ->fromQuery('SELECT id, title, slug, secret, url FROM item')
            ->field('title', 'A')
            ->field('secret', 'C', granted: 'ROLE_BOSS')
            ->sync('manual')
            ->returnable('title', 'secret', 'slug', 'url')
            ->build();
    }

    private static function fields(): SourceFields
    {
        return new SourceFields((new \ReflectionClass(SearchSession::class))->newInstanceWithoutConstructor());
    }

    private static function hit(string $kind, int|string $id): Hit
    {
        return new Hit($kind, $id, 1.0, new ScoreBreakdown());
    }

    public function testAGrantedFieldWithAnotherColumnNameGatesItsColumn(): void
    {
        $index = IndexDefinition::builder('staff')
            ->fromQuery('SELECT id, name, salary FROM emp')
            ->field('name', 'A')
            ->field('pay', 'C', column: 'salary', granted: 'ROLE_HR')
            ->sync('manual')
            ->returnable('name', 'salary')
            ->build();

        self::assertSame(['name'], SourceFields::resolve($index, (new SearchQuery('x'))->restrictedTo(['name'])), 'the clerk does not get the salary column');
        self::assertSame(['name', 'salary'], SourceFields::resolve($index, (new SearchQuery('x'))->restrictedTo(['name', 'pay'])), 'the HR viewer does');
        self::assertSame(['name', 'salary'], SourceFields::resolve($index, new SearchQuery('x')), 'an unrestricted (system) search does');
    }

    public function testAHiddenGrantedFieldLeavesAListWithoutGaps(): void
    {
        $query = (new SearchQuery('x'))->restrictedTo(['title', 'slug', 'url']);

        self::assertSame(['title', 'slug', 'url'], SourceFields::resolve(self::index(), $query));
    }

    public function testTheAskedNamesComeBackAsAListInTheOrderOfTheColumns(): void
    {
        $query = (new SearchQuery('x'))->restrictedTo(['title', 'secret', 'slug', 'url'])->returning(['slug', 'title']);

        self::assertSame(['title', 'slug'], SourceFields::resolve(self::index(), $query));
    }

    public function testANameTheViewerMayNotSeeIsRefusedLikeAnUnknownOne(): void
    {
        $query = (new SearchQuery('x'))->restrictedTo(['title'])->returning(['secret']);

        try {
            SourceFields::resolve(self::index(), $query);
            self::fail('a hidden field was returned');
        } catch (InvalidQuery $e) {
            self::assertStringContainsString('cannot return "secret". Returnable columns: title, slug, url.', $e->getMessage());
        }
    }

    public function testTheStatementReadsTheIdsOnceFromTheSourceQuery(): void
    {
        $query = new SearchQuery('x');
        $hits = [self::hit('items', 7), self::hit('items', 7), self::hit('items', 9)];

        $statement = self::fields()->statement(self::index(), $query, $hits, ['title', 'slug']);

        self::assertNotNull($statement);
        self::assertSame('returned fields: items', $statement->label);
        self::assertStringContainsString('WHERE d."id" = ANY (CAST(', $statement->sql);
        self::assertStringContainsString('d."id"::text AS fz_id', $statement->sql);
        self::assertSame(['{7,9}'], array_values($statement->params));
    }
}
