<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyValues;
use Fuzzphony\Engine\Postgres\Highlighter;
use Fuzzphony\Engine\Postgres\TypoPlan;
use Fuzzphony\Engine\Postgres\TypoTerm;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The statement and the limits of Highlighter, seen through a scripted connection: the work budget, the typo flag, the bound parameters. */
final class HighlighterTypoBudgetTest extends TestCase
{
    private const string S = "\u{E000}";
    private const string E = "\u{E001}";

    private static function plan(): TypoPlan
    {
        return new TypoPlan([new TypoTerm('hedphones')], range(1, 200));
    }

    /** @return list<string> the array literals bound to the typo statement (the words asked about) */
    private static function asked(AnswerScriptedConnection $connection): array
    {
        return array_values(array_filter($connection->log[1][1], static fn(mixed $p): bool => is_string($p) && str_starts_with($p, '{')));
    }

    /** @param array<int|string, array<string, string>> $out */
    private static function nameOf(array $out, int $id): string
    {
        return $out[$id]['name'];
    }

    /**
     * @param array<int|string, array<string, string>> $out
     *
     * @return array<string, string>
     */
    private static function nameRow(array $out, int $id): array
    {
        return $out[$id];
    }

    private static function codeIndex(): IndexDefinition
    {
        return IndexDefinition::builder('codes')
            ->fromQuery('SELECT id, name, sku, city FROM fz_code')
            ->field('name', 'A', fuzzy: true)
            ->field('sku', 'B', code: true)
            ->field('city', 'C', many: new ManyValues('address', 'customer_id', 'city', 20))
            ->language('english')
            ->sync('manual')
            ->build();
    }

    public function testAShortCodeIsReadWithItsSeparatorsAndTheTextColumnIsBoundToTheMarkers(): void
    {
        $connection = new AnswerScriptedConnection([[['id' => '1', 'h_sku' => 'x', 'c_sku' => 'x']]]);

        (new Highlighter($connection))->highlight(self::codeIndex(), ['sku'], "'inv'", [1]);

        [$sql, $params] = $connection->log[0];
        self::assertSame(1, preg_match('/translate\(coalesce\(d\."fld_sku"::text, \'\'\), :(p\d+), \'\'\) END AS "c_sku"/', $sql, $m), 'the plain text of a short code is a column of its own');
        self::assertSame(self::S . self::E, $params[$m[1] ?? ""], 'the two markers, in this order, are what is removed from it');
    }

    public function testAShortTextIsShownWholeAndALongOneAsTwoFragments(): void
    {
        $connection = new AnswerScriptedConnection([[['id' => '1', 'h_name' => 'x']]]);

        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'mouse'", [1]);

        $options = array_values(array_filter($connection->log[0][1], static fn(mixed $p): bool => is_string($p) && str_contains($p, 'MaxFragments')));
        self::assertCount(2, $options);
        self::assertStringEndsWith('MaxFragments=0', $options[0], 'a short text: no fragments, the whole text');
        self::assertStringEndsWith('MaxFragments=2', $options[1], 'a long one: two fragments');
    }

    public function testAFieldWithoutManyValuesDoesNotHideTheNextOnesThatHaveThem(): void
    {
        $connection = new AnswerScriptedConnection([[['id' => '1', 'h_name' => 'x', 'h_city' => 'y']]]);

        (new Highlighter($connection))->highlight(self::codeIndex(), ['name', 'city'], "'x'", [1]);

        self::assertStringContainsString('SELECT mv.v FROM', $connection->log[0][0], 'the many field after a plain one still shows its best value');
    }

    public function testOnlyTheTypoFlagsOfPostgresqlCountAndEveryRowIsLookedAt(): void
    {
        $rows = [
            ['id' => '1', 'h_name' => 'Plain', 'fz_typo' => false, 'x_name' => 'Wireless Headphones'],
            ['id' => '2', 'h_name' => 'Plain', 'fz_typo' => 0, 'x_name' => 'Wireless Headphones'],
            ['id' => '3', 'h_name' => 'Plain', 'fz_typo' => '0', 'x_name' => 'Wireless Headphones'],
            ['id' => '4', 'h_name' => 'Plain', 'fz_typo' => 1, 'x_name' => 'Wireless Headphones'],
            ['id' => '5', 'h_name' => 'Plain', 'fz_typo' => 't', 'x_name' => 'Wireless Headphones'],
            ['id' => '6', 'h_name' => 'Plain', 'fz_typo' => '1', 'x_name' => 'Wireless Headphones'],
            ['id' => '7', 'h_name' => 'Plain', 'fz_typo' => 2, 'x_name' => 'Wireless Headphones'],
            ['id' => '8', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => 'Wireless Headphones'],
        ];
        $connection = new AnswerScriptedConnection([$rows, [['word' => 'headphones', 'term' => 0, 'sim' => 0.8]]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", range(1, 8), self::plan());

        $marked = 'Wireless <mark>Headphones</mark>';
        self::assertSame(['1' => 'Plain', '2' => 'Plain', '3' => 'Plain', '4' => $marked, '5' => $marked, '6' => $marked, '7' => 'Plain', '8' => $marked], array_map(static fn(array $f): string => $f['name'], $out));
    }

    public function testAFieldWithNothingToCompareDoesNotStopTheNextField(): void
    {
        $rows = [['id' => '1', 'h_name' => 'Plain', 'h_brand' => 'Plain', 'fz_typo' => true, 'x_name' => ' -- ', 'x_brand' => 'Wireless Headphones']];
        $connection = new AnswerScriptedConnection([$rows, [['word' => 'headphones', 'term' => 0, 'sim' => 0.8]]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name', 'brand'], "'hedphon'", [1], self::plan());

        self::assertSame(["name" => "Plain", "brand" => "Wireless <mark>Headphones</mark>"], self::nameRow($out, 1));
    }

    public function testASkippedRowDoesNotStopTheRowsAfterIt(): void
    {
        $rows = [
            ['id' => '1', 'h_name' => 'Plain', 'fz_typo' => false, 'x_name' => 'Wireless Headphones'],
            ['id' => '2', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => 'Wireless Headphones'],
        ];
        $connection = new AnswerScriptedConnection([$rows, [['word' => 'headphones', 'term' => 0, 'sim' => 0.8]]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1, 2], self::plan());

        self::assertSame(['1' => ['name' => 'Plain'], '2' => ['name' => 'Wireless <mark>Headphones</mark>']], $out);
    }

    public function testTheWordsAskedAboutAreThoseOfTheTextNotOfTheSnippet(): void
    {
        $rows = [['id' => '1', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => 'Wireless Headphones']];
        $connection = new AnswerScriptedConnection([$rows, []]);

        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], self::plan());

        self::assertSame(['{"wireless","headphones"}'], self::asked($connection));
    }

    public function testNothingIsMarkedWhenNoTypoHitHasAWordAndEveryHitKeepsItsSnippet(): void
    {
        $rows = [
            ['id' => '1', 'h_name' => 'One', 'fz_typo' => true, 'x_name' => ' -- '],
            ['id' => '2', 'h_name' => 'Two', 'fz_typo' => true, 'x_name' => ' -- '],
            ['id' => '3', 'h_name' => 'Three', 'fz_typo' => true, 'x_name' => ' -- '],
        ];
        $connection = new AnswerScriptedConnection([$rows]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1, 2, 3], self::plan());

        self::assertSame(['1' => ['name' => 'One'], '2' => ['name' => 'Two'], '3' => ['name' => 'Three']], $out);
    }

    public function testANumericWordIsAskedAboutAsATextNotAsANumber(): void
    {
        $rows = [['id' => '1', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => 'model 2024']];
        $connection = new AnswerScriptedConnection([$rows, []]);

        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], self::plan());

        self::assertSame(['{"model","2024"}'], self::asked($connection));
    }

    public function testTheLimitOfCharactersCountsCharactersNotBytes(): void
    {
        $text = str_repeat('żółć ', 1_500) . 'koniec'; // 7 506 characters, far more than 10 000 bytes
        $connection = new AnswerScriptedConnection([[['id' => '1', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => $text]], []]);

        (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], self::plan());

        self::assertSame(['{"żółć","koniec"}'], self::asked($connection), 'a text under the limit is not cut, its last word is looked at');
    }

    public function testATextThatIsNotValidUtf8AtTheLimitHasNoWordsAndNoError(): void
    {
        $rows = [['id' => '1', 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => str_repeat("\xFF", 10_000)]];
        $connection = new AnswerScriptedConnection([$rows]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", [1], self::plan());

        self::assertSame(['1' => ['name' => 'Plain']], $out);
    }

    /** @return list<array<string, mixed>> $count typo hits, each a text of $words different words */
    private static function hits(int $count, int $words, bool $same = false): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; ++$i) {
            $text = implode(' ', array_map(static fn(int $w): string => $same ? 'ab' . $w : sprintf('w%dx%d', $i, $w), range(1, $words)));
            $rows[] = ['id' => (string) $i, 'h_name' => 'Plain', 'fz_typo' => true, 'x_name' => $text];
        }

        return $rows;
    }

    public function testThePageHasABudgetOfOneHundredThousandRunsAndTheLastHitThatFitsIsStillMarked(): void
    {
        // 101 hits of 1 000 words: the 100th spends the budget exactly, the 101st does not fit
        $connection = new AnswerScriptedConnection([self::hits(101, 1_000, true), [['word' => 'ab1', 'term' => 0, 'sim' => 0.9]]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", range(1, 101), self::plan());

        self::assertStringContainsString('<mark>ab1</mark>', self::nameOf($out, 1));
        self::assertStringContainsString('<mark>ab1</mark>', self::nameOf($out, 100), 'a hit that costs exactly what is left is still worked on');
        self::assertSame('Plain', self::nameOf($out, 101), 'the budget is spent: ts_headline\'s snippet stays');
    }

    public function testAHitIsSkippedByItsOwnCostNotByTheCostOfItsSnippet(): void
    {
        // 120 hits with a long text: only 100 fit; their snippets are short, so a cost taken from the snippet would let all of them in
        $connection = new AnswerScriptedConnection([self::hits(120, 1_000, true), [['word' => 'ab1', 'term' => 0, 'sim' => 0.9]]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", range(1, 120), self::plan());

        $marked = array_filter($out, static fn(array $f): bool => str_contains($f['name'], '<mark>'));
        self::assertCount(100, $marked);
    }

    public function testAtMostFourThousandDistinctWordsAreWorkedOnAndTheNextHitWaits(): void
    {
        // four hits of 1 000 words reach the limit of 4 000 distinct words: a fifth one is left alone, whatever PostgreSQL answers
        $rows = self::hits(5, 1_000);
        $connection = new AnswerScriptedConnection([$rows, [
            ['word' => 'w1x1', 'term' => 0, 'sim' => 0.9],
            ['word' => 'w2x1', 'term' => 0, 'sim' => 0.9],
            ['word' => 'w3x1', 'term' => 0, 'sim' => 0.9],
            ['word' => 'w4x1', 'term' => 0, 'sim' => 0.9],
            ['word' => 'w5x1', 'term' => 0, 'sim' => 0.9],
        ]]);

        $out = (new Highlighter($connection))->highlight(Indexes::products(), ['name'], "'hedphon'", range(1, 5), self::plan());

        self::assertStringContainsString('<mark>w1x1</mark>', self::nameOf($out, 1));
        self::assertStringContainsString('<mark>w2x1</mark>', self::nameOf($out, 2));
        self::assertStringContainsString('<mark>w4x1</mark>', self::nameOf($out, 4));
        self::assertSame('Plain', self::nameOf($out, 5));
    }
}
