<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyMatch;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\Sql\ParameterBag;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The word a typo most likely meant (Suggestion::$corrections) in the score of the typo-tolerant branch: the score only, never the predicate. */
final class FuzzyQueryCompilerCorrectionsTest extends TestCase
{
    private const array MOSUE = ['mosue' => ['word' => 'mouse', 'score' => 0.95]];

    /**
     * @param list<string>|null                                $allowed
     * @param array<string, array{word: string, score: float}> $corrections
     *
     * @return array{FuzzyMatch, array<string, scalar|null>}
     */
    private static function compile(string $text, array $corrections = self::MOSUE, ?array $allowed = null): array
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), new Names(), [], $allowed, true, null, $corrections);
        $params = new ParameterBag();
        $match = $compiler->compile((new QueryParser(256, 16))->parse($text)->root ?? throw new \LogicException('no query'), $params);
        self::assertNotNull($match);

        return [$match, $params->all()];
    }

    public function testATypedWordScoresItsCorrectionOnlyInTheScore(): void
    {
        [$match, $params] = self::compile('Mosue');
        [$plain] = self::compile('Mosue', []);

        self::assertSame($plain->predicate, $match->predicate, 'the hits stay');
        self::assertStringContainsString('q.cw', $match->score);
        self::assertStringContainsString('THEN 0.95 ELSE 0.0 END', $match->score);
        self::assertContains('mouse', $params);
        self::assertStringNotContainsString('cw', $plain->score . implode(' ', $plain->columns));
    }

    public function testOnlyAWordTheUserTypedWhole(): void
    {
        foreach (['mosue*', '"mosue pad"', 'other'] as $text) {
            [$match] = self::compile($text);
            self::assertStringNotContainsString('q.cw', $match->score, $text);
        }
        [$scoped] = self::compile('name:mosue');
        self::assertStringContainsString('q.cw', $scoped->score, 'a word scoped to a field: checked on that field');
    }

    public function testARestrictedViewerGetsNoCorrection(): void
    {
        [$match] = self::compile('mosue', allowed: ['name']);
        self::assertStringNotContainsString('q.cw', $match->score, 'the vocabulary holds the hidden fields');
    }
}
