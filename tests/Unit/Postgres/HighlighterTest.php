<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Engine\Postgres\Highlighter;
use Fuzzphony\Engine\Postgres\TypoPlan;
use Fuzzphony\Engine\Postgres\TypoTerm;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class HighlighterTest extends TestCase
{
    public function testSourceTextIsEscapedAndOnlyMarkersBecomeTags(): void
    {
        $marked = "<img src=x onerror=alert(1)> \u{E000}mouse\u{E001} & \"cable\"";

        self::assertSame(
            '&lt;img src=x onerror=alert(1)&gt; <mark>mouse</mark> &amp; &quot;cable&quot;',
            Highlighter::toHtml($marked),
        );
    }

    public function testNoFieldsOrNoIdsShortCircuitsToAnEmptyResultWithoutTouchingTheDatabase(): void
    {
        $highlighter = new Highlighter($this->neverCalledConnection());

        self::assertSame([], $highlighter->highlight(Indexes::products(), [], "'mouse'", [1]));
        self::assertSame([], $highlighter->highlight(Indexes::products(), ['name'], "'mouse'", []));
    }

    public function testHighlightingAnUnknownFieldThrows(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Cannot highlight "nosuch": it is not a highlightable field of index "products".');

        (new Highlighter($this->neverCalledConnection()))->highlight(Indexes::products(), ['nosuch'], "'mouse'", [1]);
    }

    public function testATypoPlanWithoutATypoTolerantFieldKeepsTheStrictStatement(): void
    {
        $connection = $this->scripted([[['id' => '1', 'h_description' => 'A mouse']]]);
        // description is not typo-tolerant in Indexes::products(): nothing to mark there
        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['description'], "'mouse'", [1], new TypoPlan([new TypoTerm('mose')], [1]));

        self::assertSame(['1' => ['description' => 'A mouse']], $out);
        self::assertCount(1, $connection->log);
        self::assertStringNotContainsString('AS "fz_typo"', $connection->log[0][0]);
    }

    public function testATypoPlanWithoutIdsOrWithAScopedTermOfAnotherFieldKeepsTheStrictStatement(): void
    {
        $none = $this->scripted([[['id' => '1', 'h_name' => 'Mouse']]]);
        (new Highlighter($none))->highlight(Indexes::products(), ['name'], "'mouse'", [1], new TypoPlan([new TypoTerm('mose')], []));
        self::assertStringNotContainsString('AS "fz_typo"', $none->log[0][0]);

        $other = $this->scripted([[['id' => '1', 'h_name' => 'Mouse']]]);
        (new Highlighter($other))->highlight(Indexes::products(), ['name'], "'mouse'", [1], new TypoPlan([new TypoTerm('rzer', field: 'brand')], [1]));
        self::assertStringNotContainsString('AS "fz_typo"', $other->log[0][0], 'a word scoped to brand marks nothing in name');
    }

    public function testAHitTheStrictQueryMatchesIsNotTouchedAndAsksNothingMore(): void
    {
        $connection = $this->scripted([[['id' => '1', 'h_name' => "\u{E000}Mouse\u{E001} <b>", 'fz_typo' => false, 'x_name' => 'Mouse <b>']]]);
        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'mouse'", [1], new TypoPlan([new TypoTerm('mose')], [1]));

        self::assertSame(['1' => ['name' => '<mark>Mouse</mark> &lt;b&gt;']], $out);
        self::assertCount(1, $connection->log, 'the one statement: ts_headline, the typo flag and the text');
        self::assertStringContainsString('AS "fz_typo"', $connection->log[0][0]);
        self::assertStringContainsString('AS "x_name"', $connection->log[0][0]);
        self::assertStringContainsString('left(coalesce(d."fld_name"::text', $connection->log[0][0], 'the text is bounded');
    }

    public function testATypoHitIsMarkedFromThePostgresqlAnswerInOneMoreStatement(): void
    {
        $connection = $this->scripted([
            [['id' => '1', 'h_name' => 'Wireless Headphones', 'fz_typo' => 't', 'x_name' => 'Wireless Headphones']],
            [['word' => 'headphones', 'term' => 0, 'sim' => '0.8']],
        ]);
        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], new TypoPlan([new TypoTerm('hedphones')], [1]));

        self::assertSame(['1' => ['name' => 'Wireless <mark>Headphones</mark>']], $out);
        self::assertCount(2, $connection->log);
        [$sql, $params] = $connection->log[1];
        self::assertStringContainsString('"public".word_similarity(q.nn, w.n)', $sql);
        self::assertStringContainsString('"public"."fuzzphony_norm"(', $sql);
        self::assertStringContainsString('CASE', $sql, 'the similarity each length needs');
        self::assertContains('hedphones', $params);
        self::assertContains('{"wireless","headphones"}', $params);
    }

    public function testAnExplicitFuzzySimilarityIsTheThresholdOfEveryTerm(): void
    {
        $connection = $this->scripted([
            [['id' => '1', 'h_name' => 'Mouse', 'fz_typo' => true, 'x_name' => 'Mouse']],
            [],
        ]);
        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'mose'", [1], new TypoPlan([new TypoTerm('mose'), new TypoTerm('hedph', prefix: true)], [1], 0.35));

        $sql = $connection->log[1][0];
        self::assertSame(2, substr_count($sql, 'x.s >= 0.35'));
        self::assertStringNotContainsString('CASE', $sql);
        self::assertStringContainsString('UNION ALL', $sql);
        self::assertStringContainsString(' 0 AS term', $sql);
        self::assertStringContainsString(' 1 AS term', $sql);
    }

    public function testThePrefixAllowanceIsPartOfThePrefixTermSimilarity(): void
    {
        $connection = $this->scripted([
            [['id' => '1', 'h_name' => 'Mouse', 'fz_typo' => true, 'x_name' => 'Mouse']],
            [],
        ]);
        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'mose'", [1], new TypoPlan([new TypoTerm('mose'), new TypoTerm('hedph', prefix: true)], [1]));

        $sql = $connection->log[1][0];
        $length = "char_length(replace(q.nn, ' ', ''))";
        self::assertSame(12, substr_count($sql, $length), 'six per term: three in each of the two length steps');
        self::assertSame(6, substr_count($sql, $length . ' - 1'), 'only the prefix term counts one letter less');
    }

    public function testATextCutAtTheLimitLosesTheWordTheCutMayHaveSplit(): void
    {
        $text = str_repeat('word ', 3_998) . 'headphones'; // exactly 20 000 characters: the limit
        self::assertSame(20_000, mb_strlen($text));
        $connection = $this->scripted([
            [['id' => '1', 'h_name' => 'word word', 'fz_typo' => true, 'x_name' => $text]],
            [],
        ]);
        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], new TypoPlan([new TypoTerm('hedphones')], [1]));

        self::assertSame(['{"word"}'], array_values(array_filter($connection->log[1][1], static fn(mixed $p): bool => is_string($p) && str_starts_with($p, '{'))), 'the last, possibly cut, word is not asked about');
    }

    public function testNothingIsAskedWhenNoWordOfAnyTypoHitIsACandidate(): void
    {
        $connection = $this->scripted([[['id' => '1', 'h_name' => 'x', 'fz_typo' => true, 'x_name' => ' -- ']]]);
        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], new TypoPlan([new TypoTerm('hedphones')], [1]));

        self::assertSame(['1' => ['name' => 'x']], $out);
        self::assertCount(1, $connection->log);
    }

    public function testAHitWithNoMatchingWordKeepsTsHeadlinesSnippet(): void
    {
        $connection = $this->scripted([
            [['id' => '1', 'h_name' => 'Plain note', 'fz_typo' => true, 'x_name' => 'Plain note']],
            [['word' => 'other', 'term' => 0, 'sim' => 1.0]],
        ]);
        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], new TypoPlan([new TypoTerm('hedphones')], [1]));

        self::assertSame(['1' => ['name' => 'Plain note']], $out);
    }

    /** @param list<list<array<string, mixed>>> $answers the rows of each statement, in order */
    private function scripted(array $answers): ScriptedConnection
    {
        return new ScriptedConnection($answers);
    }

    private function neverCalledConnection(): Connection
    {
        return new class implements Connection {
            public function fetchAll(string $sql, array $params = []): array
            {
                throw new \LogicException('must not be called');
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                throw new \LogicException('must not be called');
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('must not be called');
            }

            public function transactional(callable $callback): mixed
            {
                throw new \LogicException('must not be called');
            }
        };
    }
}
