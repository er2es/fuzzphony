<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\MatchExplainer;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;

/** What the match explainer asks the database for: nothing when there is nothing to look for, and never more than its bounds. */
final class MatchExplainerBoundsTest extends TestCase
{
    private static function index(): IndexDefinition
    {
        return IndexDefinition::builder('docs')->fromTable('doc')->field('title', 'A')->field('body', 'B')->sync('manual')->build();
    }

    /** @return array{MatchExplainer, RecordingConnection} */
    private static function explainer(): array
    {
        $connection = new RecordingConnection(static fn(): mixed => null);

        return [new MatchExplainer($connection, new Names()), $connection];
    }

    public function testNothingIsAskedWithoutAWordAFieldOrAnId(): void
    {
        [$explainer, $connection] = self::explainer();
        $index = self::index();
        $thresholds = new Thresholds();
        $term = new Term('mouse');

        self::assertSame([], $explainer->explain($index, ['title'], null, [1], $thresholds, true));
        self::assertSame([], $explainer->explain($index, [], $term, [1], $thresholds, true));
        self::assertSame([], $explainer->explain($index, ['title'], $term, [], $thresholds, true));
        self::assertSame([], $explainer->explain($index, ['nonesuch'], $term, [1], $thresholds, true), 'a name that is no field of the index is no field');
        self::assertSame([], $connection->log);
    }

    public function testAHiddenFieldScopeIsNotLookedFor(): void
    {
        [$explainer, $connection] = self::explainer();

        self::assertSame([], $explainer->explain(self::index(), ['title'], new FieldScoped('body', new Term('mouse')), [1], new Thresholds(), true));
        self::assertSame([], $connection->log);
    }

    public function testOnlyTheFirstTwentyFourDistinctWordsOfTheQueryAreLookedFor(): void
    {
        [$explainer, $connection] = self::explainer();
        $words = array_map(static fn(int $i): string => 'word' . $i, range(1, 30));

        $explainer->explain(self::index(), ['title'], new AllOf(array_map(static fn(string $w): Term => new Term($w), $words)), [1], new Thresholds(), false);

        self::assertCount(1, $connection->log);
        $bound = array_values($connection->log[0][1]);
        self::assertContains('word24', $bound);
        self::assertNotContains('word25', $bound);
        self::assertSame(24, count(array_filter($bound, static fn(mixed $v): bool => is_string($v) && str_starts_with($v, 'word'))));
    }

    public function testAnAskedScopeOnANameThatIsNoFieldMeansEveryField(): void
    {
        [$explainer, $connection] = self::explainer();

        $explainer->explain(self::index(), ['title'], new FieldScoped('nonesuch', new Term('mouse')), [1], new Thresholds(), false);

        self::assertCount(1, $connection->log);
    }
}
