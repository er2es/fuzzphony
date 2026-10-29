<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The corners of "did you mean", of the relaxation probe and of the synonym stems: what is asked of the database, and what is made of the answer. */
final class PostgresEngineSuggestionDetailsTest extends TestCase
{
    private Connection $connection;
    private IndexDefinition $index;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $this->index = Indexes::products('manual');
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
    }

    private function spy(?\Closure $fetchAll = null): InterceptingConnection
    {
        return new InterceptingConnection($this->connection, $fetchAll);
    }

    private function fuzzphony(PostgresEngine $engine, ?IndexDefinition $index = null): Fuzzphony
    {
        return new Fuzzphony($engine, new IndexRegistry([$index ?? $this->index]));
    }

    private function vocabulary(string ...$words): void
    {
        $this->connection->execute('TRUNCATE "fuzzphony_products__vocab"');
        foreach (array_values($words) as $i => $word) {
            $this->connection->execute('INSERT INTO "fuzzphony_products__vocab" (word, freq) VALUES (:w, :f)', ['w' => $word, 'f' => 10 - $i]);
        }
    }

    private static function setConfigStatements(InterceptingConnection $spy): int
    {
        return count(array_filter($spy->log, static fn(array $e): bool => str_contains($e[0], 'set_config')));
    }

    public function testTheSimilarityThresholdIsRestoredOnlyInsideTheCallersTransaction(): void
    {
        $spy = $this->spy();
        $fuzzphony = $this->fuzzphony(new PostgresEngine($spy));
        $suggest = static fn(): ?string => $fuzzphony->in('products')->query('hedphones')->thresholds(['fuzzy_mode' => 'never'])->get()->didYouMean;

        self::assertSame('headphones', $suggest());
        self::assertSame(1, self::setConfigStatements($spy), 'no transaction of the caller: the setting dies with the lookup\'s own');

        $spy->reset();
        self::assertSame('headphones', $spy->transactional($suggest));
        self::assertSame(2, self::setConfigStatements($spy), 'inside the caller\'s transaction the previous value is put back');
    }

    public function testExplainRestoresTheSettingOnlyInsideTheCallersTransaction(): void
    {
        $spy = $this->spy();
        $fuzzphony = $this->fuzzphony(new PostgresEngine($spy));
        $explain = static fn(): int => count($fuzzphony->in('products')->query('wireless mouse')->thresholds(['fuzzy_mode' => 'always', 'did_you_mean' => false])->explain()->plan);

        self::assertGreaterThan(0, $explain());
        self::assertSame(4, self::setConfigStatements($spy), 'each of the two words is probed, the search sets the threshold, explain() sets it again');

        $spy->reset();
        $spy->transactional($explain);
        self::assertSame(8, self::setConfigStatements($spy), 'and each puts the previous value back');
    }

    public function testAWordIsMeasuredInCharactersNotBytes(): void
    {
        $this->vocabulary('mouse', 'wireless');
        $statements = $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query('éé')->explain()->statements;

        self::assertNotContains('did you mean', array_column($statements, 'label'), 'two letters are shorter than the minimum of three, in whatever bytes');

        $statements = $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query('ééé')->explain()->statements;
        self::assertContains('did you mean', array_column($statements, 'label'), 'three letters are enough');
    }

    public function testTheDistanceAllowedIsAThirdOfTheLettersNotOfTheBytes(): void
    {
        $search = fn(string $typed): ?string => $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query($typed)->get()->didYouMean;

        $this->vocabulary('приветик');
        self::assertSame('приветик', $search('приветиа'), 'one letter away');

        $this->vocabulary('приветжжж');
        self::assertNull($search('приветик'), 'three letters away from eight is more than a third, though it is under a third of the bytes');
    }

    public function testAWordWithNoCandidateOrOnlyDistantOnesDoesNotStopTheNextWord(): void
    {
        $this->connection->execute("INSERT INTO \"fuzzphony_products__vocab\" (word, freq) VALUES ('cable', 3)");
        $search = fn(string $typed): ?string => $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query($typed)->get()->didYouMean;

        self::assertSame('zzzzzzz mouse', $search('zzzzzzz mose'), 'no candidate at all');
        self::assertSame('cabxy mouse', $search('cabxy mose'), 'cable is two edits from cabxy: too far for five letters');
        self::assertSame('cable mouse', $search('cabel mose'), 'a swap is one edit');
    }

    public function testAWordWithADigitOfAnyScriptIsACodeNotASpelling(): void
    {
        $this->vocabulary('mouse', 'wireless');

        $statements = $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query('abc٣')->explain()->statements;

        self::assertNotContains('did you mean', array_column($statements, 'label'));
    }

    public function testAKnownWordDoesNotStopTheWordsAfterIt(): void
    {
        $spy = $this->spy(static fn(string $sql): ?array => str_contains($sql, 'AS known') ? [
            ['w' => 'zzzz', 'n' => 'zzzz', 'known' => true, 'word' => null, 'freq' => null],
            ['w' => 'hedphones', 'n' => 'hedphones', 'known' => false, 'word' => 'headphones', 'freq' => 3],
        ] : null);

        self::assertSame('zzzz headphones', $this->fuzzphony(new PostgresEngine($spy))->in('products')->query('zzzz hedphones')->get()->didYouMean);
    }

    public function testTheDistanceAllowedIsCountedInCharactersOfTheEditNotOfItsBytes(): void
    {
        $this->vocabulary('приветяю');

        // two letters differ, each in both bytes: two edits, though four bytes
        self::assertSame('приветяю', $this->fuzzphony(new PostgresEngine($this->connection))->in('products')->query('приветаб')->get()->didYouMean);
    }

    public function testAShortWordMayBeOneEditAwayFromTheWordTheIndexHas(): void
    {
        $index = $this->index->withThresholds(new Thresholds(fuzzyMinLength: 2));
        $this->vocabulary('abc');

        self::assertSame('abc', $this->fuzzphony(new PostgresEngine($this->connection), $index)->in('products')->query('ab')->get()->didYouMean);
    }

    public function testASynonymMemberTypedInTheQueryIsStemmedOnce(): void
    {
        $spy = $this->spy();
        $index = $this->index->withSynonyms(Synonyms::fromEntries([['tv', 'television']]));

        $this->fuzzphony(new PostgresEngine($spy), $index)->in('products')->query('tv')->thresholds(['did_you_mean' => false])->get();

        $words = explode(chr(31), (string) ($spy->params('SELECT w, CASE WHEN w')[0]['words'] ?? ''));
        sort($words);
        self::assertSame(['television', 'tv'], $words);
    }

    public function testAQueryWithNothingToExpandAsksForNoStems(): void
    {
        $spy = $this->spy();
        $index = $this->index->withSynonyms(Synonyms::fromEntries([['tv', 'television']]));

        $this->fuzzphony(new PostgresEngine($spy), $index)->in('products')->query('mou*')->thresholds(['did_you_mean' => false])->get();

        self::assertSame([], $spy->statements('SELECT w, CASE WHEN w'), 'a prefix is not expanded');
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function knownMarks(): iterable
    {
        yield 'true' => [true, null];
        yield '"t"' => ['t', null];
        yield '"true"' => ['true', null];
        yield 'the number 1' => [1, null];
        yield '"1"' => ['1', null];
        yield 'false' => [false, 'headphones'];
        yield '"f"' => ['f', 'headphones'];
        yield 'the number 0' => [0, 'headphones'];
    }

    #[DataProvider('knownMarks')]
    public function testAWordTheLookupMarksKnownIsNotCorrectedWhateverTheDriverCallsTrue(mixed $known, ?string $expected): void
    {
        $spy = $this->spy(static fn(string $sql): ?array => str_contains($sql, 'AS known') ? [
            ['w' => 'hedphones', 'n' => 'hedphones', 'known' => $known, 'word' => 'headphones', 'freq' => 3],
        ] : null);

        self::assertSame($expected, $this->fuzzphony(new PostgresEngine($spy))->in('products')->query('hedphones')->get()->didYouMean);
    }

    public function testWordsTheProbeFindsToMatchNothingAreDroppedWhateverTheDriverCallsFalse(): void
    {
        $spy = $this->spy(static fn(string $sql): ?array => str_contains($sql, ' AS l0') ? [['l0' => 0, 'l1' => 1]] : null);

        $result = $this->fuzzphony(new PostgresEngine($spy))->in('products')->query('qqqq mouse')->thresholds(['did_you_mean' => false])->get();

        self::assertSame(['No results for all words; ignored words that match nothing: "qqqq".'], $result->warnings);
        self::assertSame('mouse', $result->interpretedAs);
        self::assertNotSame([], $result->hits);
    }

    /** @return list<list<string>> pairs of words that no other pair shares */
    private static function pairs(int $count, int $from = 0): array
    {
        $groups = [];
        for ($i = $from; $i < $from + $count; ++$i) {
            $groups[] = [sprintf('m%05da', $i), sprintf('m%05db', $i)];
        }

        return $groups;
    }

    /** @return iterable<string, array{int, bool, int}> */
    public static function synonymLists(): iterable
    {
        yield '5000 words are one batch' => [2_500, false, 1];
        yield '5001 words are two batches, whose stems are all kept' => [2_499, true, 2];
    }

    #[DataProvider('synonymLists')]
    public function testALongListIsStemmedInBatchesOfFiveThousandWords(int $pairs, bool $oneMore, int $statements): void
    {
        $groups = self::pairs($pairs);
        if ($oneMore) {
            $groups[] = ['m99999a', 'm99999b', 'm99999c'];
        }
        $spy = $this->spy();
        $engine = new PostgresEngine($spy);

        $stemmed = $engine->stemSynonyms($this->index, Synonyms::fromEntries($groups));

        self::assertCount($pairs * 2 + ($oneMore ? 3 : 0), $stemmed->stems);
        self::assertSame("'\"public\".\"fuzzphony_english\"'::regconfig", $stemmed->stemConfig);
        self::assertCount($statements, $spy->statements('SELECT w, CASE WHEN w'));
    }

    public function testAWordOfSeveralGroupsIsStemmedOnce(): void
    {
        $spy = $this->spy();

        (new PostgresEngine($spy))->stemSynonyms($this->index, Synonyms::fromEntries([['alpha', 'beta'], ['alpha', 'gamma'], 'beta => delta']));

        $asked = $spy->params('SELECT w, CASE WHEN w');
        self::assertCount(1, $asked);
        $words = explode(chr(31), (string) $asked[0]['words']);
        sort($words);
        self::assertSame(['alpha', 'beta', 'delta', 'gamma'], $words);
    }

    public function testStemsPreparedForTheSynonymsAreNotFetchedAgainWithTheSearch(): void
    {
        $spy = $this->spy();
        $engine = new PostgresEngine($spy);
        $synonyms = $engine->stemSynonyms($this->index, Synonyms::fromEntries([['tv', 'television']]));
        $spy->reset();

        $result = $this->fuzzphony($engine, $this->index->withSynonyms($synonyms))->in('products')->query('mouse')->thresholds(['did_you_mean' => false])->get();

        self::assertNotSame([], $result->hits);
        self::assertSame([['words' => 'mouse']], $spy->params('SELECT w, CASE WHEN w'), 'only the word of the query');
    }

    public function testTheStemCacheIsKeptWhileItHoldsNoMoreThanFiveThousandWords(): void
    {
        // 4999 words of synonyms and the word of the query: exactly 5000 stems after the first search
        $groups = [...self::pairs(1_665, 0)];
        foreach (range(0, 1_664) as $i) {
            $groups[$i][] = sprintf('m%05dc', $i);
        }
        $groups[] = ['x00001a', 'x00001b'];
        $groups[] = ['x00002a', 'x00002b'];
        $index = $this->index->withSynonyms(Synonyms::fromEntries($groups));
        $spy = $this->spy();
        $fuzzphony = $this->fuzzphony(new PostgresEngine($spy), $index);
        $search = static fn(): SearchResult => $fuzzphony->in('products')->query('mouse')->thresholds(['did_you_mean' => false])->get();

        $search();
        $spy->reset();
        $search();

        self::assertSame([], $spy->statements('SELECT w, CASE WHEN w'), 'every stem it needs is cached');
    }

    public function testTheSynonymsOfTwoTextConfigurationsAreNotMixedUp(): void
    {
        $english = $this->index->withSynonyms(Synonyms::fromEntries([['running', 'sprint']]));
        $simple = $english->withText(new TextConfig('simple'));
        $this->fuzzphony(new PostgresEngine($this->connection), $simple)->schema()->apply($this->connection);
        $engine = new PostgresEngine($this->connection);

        // English reduces "running" to "run": the group answers to "run"; "simple" keeps the words as they are
        self::assertStringContainsString('sprint', (string) $this->fuzzphony($engine, $english)->in('products')->query('run')->get()->interpretedAs);
        self::assertSame('run', $this->fuzzphony($engine, $simple)->in('products')->query('run')->get()->interpretedAs);
    }
}
