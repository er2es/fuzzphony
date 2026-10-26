<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A hit found only through typo tolerance gets its mistyped words marked in the typo-tolerant fields: the words of the
 * text that are the typo-tolerant match of the typed ones. A hit the strict (full-text) branch found keeps the highlight
 * ts_headline gave it, byte for byte.
 *
 * title (A) and body (B) are typo-tolerant, extra (C) is not, secret (D) is typo-tolerant and hidden from a restricted viewer.
 */
final class TypoHighlightTest extends TestCase
{
    private const string LONG = 'alpha bravo charlie delta echo foxtrot golf hotel india juliet kilo lima mike november oscar papa quebec romeo sierra tango uniform victor whiskey xray yankee zulu alpha bravo charlie delta echo foxtrot';

    private Connection $connection;
    private InterceptingConnection $log;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_typo_doc, fuzzphony_typo_docs, fuzzphony_typo_docs__vocab, fuzzphony_typo_docs__next, fuzzphony_typo_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_typo_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, extra text NOT NULL, secret text NOT NULL)');
        $rows = [
            [1, 'Wireless Headphones', 'A pair of over-ear headphones with a long battery', 'headphones for travel', 'hidden zebrafish'],
            [2, 'Café Crème', 'Fresh <script>alert(1)</script> coffee & "cream"', 'plain', 'nothing'],
            [3, 'Wireless mouse', 'Silent mouse', 'mouse', 'nothing'],
            [4, 'Wired headphones', 'Cable included', 'nothing', 'nothing'],
            [5, 'Notes', self::LONG . ' headphones ' . self::LONG . ' ' . self::LONG . ' headphones at the end', 'long', 'nothing'],
            [6, 'Old televsion set', 'Needs repair', 'nothing', 'nothing'],
            [7, "Men's jacket", "Joe's jacket, a jacket for jackets", 'jacket', 'nothing'],
            [8, 'Zażółć gęślą jaźń', 'Zażółć gęślą jaźń wielokrotnie ' . str_repeat('żółć gęśl jaźń ', 40) . 'jaźń', 'nothing', 'nothing'],
            [9, 'Plain note', 'headphones', 'nothing', 'a wireles secret'],
            [10, 'Red shoes', 'big red shoes for kids', 'shoes', 'nothing'],
        ];
        foreach ($rows as [$id, $title, $body, $extra, $secret]) {
            $this->connection->execute('INSERT INTO fz_typo_doc VALUES (:id, :title, :body, :extra, :secret)', ['id' => $id, 'title' => $title, 'body' => $body, 'extra' => $extra, 'secret' => $secret]);
        }
        $this->log = new InterceptingConnection($this->connection);
        $index = IndexDefinition::builder('typo_docs')
            ->fromQuery('SELECT id, title, body, extra, secret FROM fz_typo_doc')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'B', fuzzy: true)
            ->field('extra', 'C')
            ->field('secret', 'D', fuzzy: true)
            ->language('english')
            ->sync('manual')
            ->build()
            ->withSynonyms(Synonyms::fromEntries([['tv', 'television']]));
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->log), new IndexRegistry([$index]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('typo_docs');
    }

    public function testAHitFoundOnlyByTypoToleranceMarksTheMistypedWordInTheTypoTolerantFields(): void
    {
        $hit = $this->hit(1, $this->search('hedphones', ['title', 'body', 'extra']));

        self::assertSame('Wireless <mark>Headphones</mark>', $hit->highlights['title'], 'the typed word is hedphones: the word of the text is marked, the original characters');
        self::assertSame('A pair of over-ear <mark>headphones</mark> with a long battery', $hit->highlights['body']);
        self::assertSame('headphones for travel', $hit->highlights['extra'], 'extra is not typo-tolerant: it is not where the hit was found, ts_headline\'s snippet stays');
        self::assertSame(0.0, $hit->breakdown->textRank, 'found by typo tolerance only');
    }

    /** Swapped letters are found by the search (whole-word variants); the highlight marks them as well, though trigram similarity does not reach them. */
    public function testASwappedWordIsMarkedLikeAnyOtherTypo(): void
    {
        $result = $this->search('mosue', ['title', 'body'])->get();

        self::assertContains(3, $result->ids());
        $hit = $this->hitIn($result, 3);
        self::assertSame(0.0, $hit->breakdown->textRank, 'found by typo tolerance only');
        self::assertSame('Wireless <mark>mouse</mark>', $hit->highlights['title']);
        self::assertSame('Silent <mark>mouse</mark>', $hit->highlights['body']);
    }

    public function testEveryHitOfATypoSearchIsMarkedNotOnlyTheFirst(): void
    {
        $result = $this->search('hedphones', ['title'])->get();

        self::assertEqualsCanonicalizing([1, 4, 5, 9], $result->ids());
        self::assertSame('Wired <mark>headphones</mark>', $this->hit(4, $this->search('hedphones', ['title']))->highlights['title']);
        self::assertSame('Plain note', $this->hit(9, $this->search('hedphones', ['title']))->highlights['title'], 'the title has no such word: the snippet stays as ts_headline gave it');
    }

    public function testAStrictHitKeepsItsStrictHighlightByteForByte(): void
    {
        foreach (['headphones', 'mouse', 'wireless', 'wireless mouse', 'headphone', 'jacket', '"red shoes"', 'headphones OR wireles'] as $query) {
            $never = $this->search($query, ['title', 'body', 'extra'], 'never')->get();
            foreach (['fallback', 'always'] as $mode) {
                $typo = $this->search($query, ['title', 'body', 'extra'], $mode)->get();
                foreach ($typo->hits as $hit) {
                    if ($hit->breakdown->textRank > 0.0) {
                        $strict = array_values(array_filter($never->hits, static fn($h): bool => $h->id === $hit->id))[0] ?? self::fail(sprintf('hit %s of "%s" (%s) is not a strict hit', $hit->id, $query, $mode));
                        self::assertSame($strict->highlights, $hit->highlights, sprintf('"%s", %s, hit %s', $query, $mode, $hit->id));
                    }
                }
            }
        }
    }

    public function testAHitThatMatchedStrictlyAndAnotherByTypoIsNotMixedUp(): void
    {
        // the typo of "wireles" does not mark Wireless in a hit the strict branch found (it matched "headphones")
        $result = $this->search('wireles OR headphones', ['title'], 'always')->get();
        $strict = $this->hit(1, $this->search('wireles OR headphones', ['title'], 'always'));
        self::assertGreaterThan(0.0, $strict->breakdown->textRank);
        self::assertSame('Wireless <mark>Headphones</mark>', $strict->highlights['title']);
        self::assertNotSame([], $result->hits);
    }

    public function testATypoHitWithSomeWordsMatchedExactlyMarksBothKindsOfWords(): void
    {
        $title = $this->hit(1, $this->search('wireles headphones', ['title']))->highlights['title'];

        self::assertSame('<mark>Wireless</mark> <mark>Headphones</mark>', $title, 'ts_headline marks the exact word, the typo word is added');
        self::assertSame('<mark>Wireless</mark> <mark>mouse</mark>', $this->hit(3, $this->search('wireles mouse', ['title']))->highlights['title']);
    }

    public function testNoTypoMarksWithoutTypoTolerance(): void
    {
        $result = $this->search('wireles headphones', ['title'], 'never')->get();

        self::assertNotContains(1, $result->ids(), 'wireles is not a word of the text, and every word is needed');
        $partial = $this->search('wireles OR headphones', ['title'], 'never')->get();
        self::assertSame('Wireless <mark>Headphones</mark>', $this->hit(1, $this->search('wireles OR headphones', ['title'], 'never'))->highlights['title']);
        self::assertNotSame([], $partial->hits);
    }

    public function testARestrictedViewerGetsTheMarksOfItsFieldsOnly(): void
    {
        $viewer = $this->search('hedphones', ['title', 'body', 'secret'])->restrictedToFields('title', 'body');
        $hit = $this->hit(1, $viewer);

        self::assertSame(['title', 'body'], array_keys($hit->highlights), 'a hidden field gets no highlight');
        self::assertSame('Wireless <mark>Headphones</mark>', $hit->highlights['title']);
        // a typo that only the hidden field has finds nothing for the restricted viewer, and so marks nothing
        self::assertSame([], $this->search('wireles', ['title', 'secret'])->restrictedToFields('body')->get()->ids());
        $onlyTitle = $this->hit(1, $this->search('hedphones', ['title', 'body'])->restrictedToFields('title'));
        self::assertSame(['title'], array_keys($onlyTitle->highlights));
        // the unrestricted viewer sees the hidden field's typo marks
        self::assertSame('hidden <mark>zebrafish</mark>', $this->hit(1, $this->search('zebrafsh', ['secret']))->highlights['secret']);
    }

    public function testAFieldScopedWordIsMarkedInItsFieldOnly(): void
    {
        $hit = $this->hit(1, $this->search('title:hedphones', ['title', 'body']));

        self::assertSame('Wireless <mark>Headphones</mark>', $hit->highlights['title']);
        self::assertSame('A pair of over-ear headphones with a long battery', $hit->highlights['body'], 'the word is scoped to the title: the body is not marked');
        $body = $this->hit(1, $this->search('body:hedphones', ['title', 'body']));
        self::assertSame('Wireless Headphones', $body->highlights['title']);
        self::assertSame('A pair of over-ear <mark>headphones</mark> with a long battery', $body->highlights['body']);
    }

    public function testAnExcludedWordIsNeverMarked(): void
    {
        $hit = $this->hit(1, $this->search('hedphones -batery', ['title', 'body']));

        self::assertSame('A pair of over-ear <mark>headphones</mark> with a long battery', $hit->highlights['body'], 'battery is a typo of the excluded batery: not marked');
        self::assertNotContains(4, $this->search('hedphones -wired', ['title'])->get()->ids(), 'excluded exactly');
    }

    public function testASynonymAlternativeThatMatchedByTypoIsMarked(): void
    {
        $hit = $this->hit(6, $this->search('tv', ['title', 'extra']));

        self::assertSame('Old <mark>televsion</mark> set', $hit->highlights['title'], 'tv expands to television, which the text has misspelt');
    }

    public function testAccentsAndCaseAreFoldedForTheMatchAndTheOriginalCharactersAreMarked(): void
    {
        $hit = $this->hit(2, $this->search('creem', ['title']));

        self::assertSame('Café <mark>Crème</mark>', $hit->highlights['title']);
        self::assertSame('Café <mark>Crème</mark>', $this->hit(2, $this->search('CREEM', ['title']))->highlights['title']);
    }

    public function testTheTextStaysEscapedAndOnlyMarkIsAddedBack(): void
    {
        $hit = $this->hit(2, $this->search('cofee', ['body']));

        self::assertSame('Fresh &lt;script&gt;alert(1)&lt;/script&gt; <mark>coffee</mark> &amp; &quot;cream&quot;', $hit->highlights['body']);
        self::assertStringNotContainsString('<script', $hit->highlights['body']);
    }

    public function testApostrophesSeparateWordsAsInTheSearch(): void
    {
        $hit = $this->hit(7, $this->search('jackt', ['title', 'body']));

        self::assertSame('Men&#039;s <mark>jacket</mark>', $hit->highlights['title']);
        self::assertSame('Joe&#039;s <mark>jacket</mark>, a <mark>jacket</mark> for <mark>jackets</mark>', $hit->highlights['body'], 'every word of the text that is similar enough, repeated ones too');
    }

    public function testAPhraseMarksTheRunOfWordsThatMatched(): void
    {
        $hit = $this->hit(10, $this->search('"red shoas"', ['title', 'body']));

        self::assertSame('<mark>Red</mark> <mark>shoes</mark>', $hit->highlights['title']);
        self::assertStringContainsString('big <mark>red</mark> <mark>shoes</mark> for kids', $hit->highlights['body']);
    }

    public function testALongTextGetsASnippetAroundTheMatchNotTheWholeText(): void
    {
        $hit = $this->hit(5, $this->search('hedphones', ['body']));
        $body = $hit->highlights['body'];

        self::assertStringContainsString('<mark>headphones</mark>', $body);
        self::assertStringContainsString(' … ', $body, 'two fragments, as ts_headline');
        self::assertLessThan(300, mb_strlen($body));
        self::assertSame(2, substr_count($body, '<mark>'));
    }

    public function testMultibyteTextIsNeverCutInsideACharacter(): void
    {
        $hit = $this->hit(8, $this->search('jaxn', ['title', 'body']));

        foreach ($hit->highlights as $field => $html) {
            self::assertTrue(mb_check_encoding($html, 'UTF-8'), $field);
        }
        $long = $this->hit(8, $this->search('zolx', ['body']));
        self::assertTrue(mb_check_encoding($long->highlights['body'], 'UTF-8'));
        self::assertStringContainsString('<mark>', $long->highlights['body']);
        self::assertLessThanOrEqual(30, preg_match_all('/\p{L}+/u', strip_tags($long->highlights['body'])), 'two fragments of fifteen words, as ts_headline MaxWords');
    }

    public function testAHiddenFieldNeverDecidesWhatARestrictedViewerSees(): void
    {
        $seen = [];
        foreach (['', 'salary negotiation', 'contains wireless', 'logitech logitek salary wireles'] as $i => $secret) {
            $this->connection->execute('DELETE FROM fz_typo_doc');
            $this->connection->execute('INSERT INTO fz_typo_doc VALUES (:id, :title, :body, :extra, :secret)', ['id' => 1, 'title' => 'Logitech wireles mouse', 'body' => 'plain', 'extra' => 'plain', 'secret' => $secret]);
            $this->fuzzphony->reindex('typo_docs');
            foreach (['fallback', 'always'] as $mode) {
                foreach (['logitek | salary', 'logitek | wireless', 'logitek wireles', 'logitek'] as $query) {
                    $hits = $this->search($query, ['title', 'body'], $mode)->restrictedToFields('title', 'body')->get()->hits;
                    $seen[$mode . '|' . $query][$i] = array_map(static fn($h): array => [$h->id, $h->score, $h->highlights], $hits);
                }
            }
        }
        foreach ($seen as $case => $perSecret) {
            self::assertCount(1, array_unique(array_map('serialize', $perSecret)), $case . ': the hidden field changes nothing the viewer sees');
        }
        self::assertSame('<mark>Logitech</mark> wireles mouse', $seen['always|logitek | salary'][0][0][2]['title'] ?? self::fail('no hit'), 'and the typo word is marked');
    }

    public function testATextThatHoldsOurMarkersCannotDrawAMark(): void
    {
        $this->connection->execute('DELETE FROM fz_typo_doc');
        $rows = [
            [1, "x\u{E000} wireless <b>\u{E001}"],
            [2, "lone \u{E000} wireless"],
            [3, "wireles\u{E001} mouse"],
        ];
        foreach ($rows as [$id, $title]) {
            $this->connection->execute('INSERT INTO fz_typo_doc VALUES (:id, :title, :body, :extra, :secret)', ['id' => $id, 'title' => $title, 'body' => 'plain', 'extra' => 'plain', 'secret' => 'plain']);
        }
        $this->fuzzphony->reindex('typo_docs');

        $strict = $this->search('wireless', ['title'], 'never')->get();
        self::assertSame('<mark>wireless</mark>', $this->hitIn($strict, 1)->highlights['title'], 'a strict hit: ts_headline cuts the short words and the tag, and the planted markers are gone');
        self::assertSame('lone  <mark>wireless</mark>', $this->hitIn($strict, 2)->highlights['title']);
        foreach (['wireless', 'wireles'] as $query) {
            foreach ($this->search($query, ['title'], 'always')->get()->hits as $hit) {
                $html = $hit->highlights['title'];
                self::assertSame(substr_count($html, '<mark>'), substr_count($html, '</mark>'), $html);
                self::assertStringNotContainsString("\u{E000}", $html);
                self::assertStringNotContainsString("\u{E001}", $html);
            }
        }
        self::assertSame('<mark>wireles</mark> mouse', $this->hitIn($this->search('wirelss', ['title'])->get(), 3)->highlights['title']);
    }

    public function testARelaxedSearchMarksTheWordsOfTheQueryThatWasRun(): void
    {
        $result = $this->search('hedphones zzqxyw', ['title'])->get();

        self::assertContains('No results for all words; ignored words that match nothing: "zzqxyw".', $result->warnings, 'relaxed: the second search is the one that is highlighted');
        self::assertNotSame([], $result->hits);
        self::assertSame('Wireless <mark>Headphones</mark>', $this->hitIn($result, 1)->highlights['title']);
    }

    public function testNothingExtraRunsWhenNoHighlightIsAsked(): void
    {
        $typoSearch = fn(bool $highlight): SearchBuilder => $highlight
            ? $this->fuzzphony->in('typo_docs')->query('hedphones')->highlight('title')
            : $this->fuzzphony->in('typo_docs')->query('hedphones');
        $typoSearch(false)->get(); // warm: the stop word answers are kept per process
        $without = $this->statementsOf(static fn(): mixed => $typoSearch(false)->get());

        self::assertSame([], array_filter($without, static fn(string $sql): bool => str_contains($sql, 'ts_headline') || str_contains($sql, 'word_similarity(q.nn')), 'no highlight statement, no typo statement');
        // the strict headline statement, and the one that compares the words of the hits found by typo tolerance
        self::assertCount(count($without) + 2, $this->statementsOf(static fn(): mixed => $typoSearch(true)->get()));
    }

    public function testASearchWithoutTypoToleranceHasTheStrictHeadlineStatementOnly(): void
    {
        $strict = fn(bool $highlight): SearchBuilder => $highlight
            ? $this->fuzzphony->in('typo_docs')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->highlight('title')
            : $this->fuzzphony->in('typo_docs')->query('mouse')->thresholds(['fuzzy_mode' => 'never']);
        $strict(false)->get();
        $without = $this->statementsOf(static fn(): mixed => $strict(false)->get());
        $with = $this->statementsOf(static fn(): mixed => $strict(true)->get());

        self::assertCount(count($without) + 1, $with, 'the headline statement only');
        self::assertSame('Wireless <mark>mouse</mark>', $this->hitIn($strict(true)->get(), 3)->highlights['title']);
        self::assertStringNotContainsString('AS "fz_typo"', implode("
", $with), 'the statements are the strict ones');
    }

    /**
     * @param \Closure(): mixed $run
     *
     * @return list<string> the SQL of the statements it ran
     */
    private function statementsOf(\Closure $run): array
    {
        $this->log->log = [];
        $run();

        return array_map(static fn(array $entry): string => $entry[0], $this->log->log);
    }

    /** @param list<string> $fields */
    private function search(string $query, array $fields, string $mode = 'fallback'): SearchBuilder
    {
        return $this->fuzzphony->in('typo_docs')->query($query)->limit(50)->highlight(...$fields)->thresholds(['fuzzy_mode' => $mode]);
    }

    private function hit(int $id, SearchBuilder $search): \Fuzzphony\Core\Search\Hit
    {
        return $this->hitIn($search->get(), $id);
    }

    private function hitIn(\Fuzzphony\Core\Search\SearchResult $result, int $id): \Fuzzphony\Core\Search\Hit
    {
        foreach ($result->hits as $hit) {
            if ($hit->id === $id) {
                return $hit;
            }
        }
        self::fail(sprintf('hit %d not found; found %s', $id, implode(', ', $result->ids())));
    }
}
