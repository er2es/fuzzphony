<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\MatchedWord;
use Fuzzphony\Core\Search\ScoreBreakdown;
use PHPUnit\Framework\TestCase;

final class HitTest extends TestCase
{
    public function testAHitKnowsItsKindAndKeepsTheTypeOfItsId(): void
    {
        $int = new Hit('products', 7, 1.5, new ScoreBreakdown());
        $string = new Hit('articles', 'a-7', 1.5, new ScoreBreakdown());

        self::assertSame('products', $int->kind);
        self::assertSame(7, $int->id);
        self::assertSame('a-7', $string->id);
        self::assertNull($int->mergedScore);
        self::assertNull($int->matches);
    }

    public function testTheMergedScoreAndTheMatchesAreAddedToACopy(): void
    {
        $hit = new Hit('products', 7, 1.5, new ScoreBreakdown(), ['name' => '<mark>mouse</mark>']);
        $merged = $hit->withMergedScore(0.25)->withMatches([new MatchedWord('name', 'mouse', MatchedWord::EXACT)]);

        self::assertNull($hit->mergedScore);
        self::assertSame(0.25, $merged->mergedScore);
        self::assertSame(['name' => '<mark>mouse</mark>'], $merged->highlights);
        self::assertSame('mouse', $merged->matches[0]->word ?? null);
    }

    public function testToArrayAndJsonUseTheSameStableKeys(): void
    {
        $hit = (new Hit('products', 7, 1.5, new ScoreBreakdown(textRank: 0.5), ['name' => '<mark>mouse</mark>']))->withMatches([new MatchedWord('name', 'mouse', MatchedWord::TYPO, 0.8)]);

        self::assertSame(['kind', 'id', 'score', 'breakdown', 'highlights', 'mergedScore', 'matches'], array_keys($hit->toArray()));
        self::assertSame([['field' => 'name', 'word' => 'mouse', 'via' => 'typo', 'similarity' => 0.8]], $hit->toArray()['matches']);
        self::assertSame(json_encode($hit->toArray()), json_encode($hit));
        self::assertStringContainsString('"id":7', (string) json_encode($hit));
    }
}
