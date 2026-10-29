<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\TextConfigCache;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Which leaf tsqueries are stop words is a property of the text search configuration: PostgreSQL is asked once per
 * tsquery and engine, the answer ("empty" or "not empty") is kept, and a search asks only for what it has not seen.
 */
final class EmptyQueriesCacheTest extends TestCase
{
    private const string LOOKUP = 'SELECT t.q FROM unnest(';

    private Connection $connection;
    private InterceptingConnection $spy;
    private Fuzzphony $fuzzphony;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->connection, EngineConformanceTestCase::fixtureRows());
        $index = Indexes::products('manual')->withThresholds(new Thresholds(didYouMean: false));
        $setup = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $setup->schema()->apply($this->connection);
        $setup->reindex('products');
        $this->spy = new InterceptingConnection($this->connection);
        $this->engine = new PostgresEngine($this->spy);
        $this->fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
    }

    /** @return list<int|string> */
    private function ids(string $query): array
    {
        return $this->fuzzphony->in('products')->query($query)->thresholds(['fuzzy_mode' => 'always'])->get()->ids();
    }

    public function testTheSameSearchAgainDoesNotAskPostgreSqlAgain(): void
    {
        $first = $this->ids('wireles mose');
        self::assertCount(1, $this->spy->statements(self::LOOKUP));

        self::assertSame($first, $this->ids('wireles mose'));
        self::assertCount(1, $this->spy->statements(self::LOOKUP), 'both answers are kept');
        self::assertContains(1, $first);
    }

    public function testAStopWordStaysAStopWordFromTheKeptAnswer(): void
    {
        // "for" and "the" reduce to nothing: the per-word fuzzy branch must still ignore them the second time
        $first = $this->ids('wireless mouse for the offic');
        $second = $this->ids('wireless mouse for the offic');

        self::assertSame($first, $second);
        self::assertContains(1, $first);
        self::assertCount(1, $this->spy->statements(self::LOOKUP));
    }

    public function testASearchAsksOnlyForTheTsqueriesItHasNotSeen(): void
    {
        $this->ids('wireles');
        $this->spy->reset();

        $this->ids('wireles mose');

        $asked = $this->spy->params(self::LOOKUP);
        self::assertCount(1, $asked);
        self::assertStringContainsString('mose', (string) $asked[0]['queries']);
        self::assertStringNotContainsString('wireles', (string) $asked[0]['queries'], 'a word that is known is not asked again');
    }

    public function testAnotherEngineStartsWithoutAnswers(): void
    {
        $this->ids('wireles mose');
        $other = new Fuzzphony(new PostgresEngine($this->spy), new IndexRegistry([Indexes::products('manual')->withThresholds(new Thresholds(didYouMean: false))]));
        $this->spy->reset();

        $other->in('products')->query('wireles mose')->thresholds(['fuzzy_mode' => 'always'])->get();

        self::assertCount(1, $this->spy->statements(self::LOOKUP));
    }

    public function testAfterResetCachesTheStopWordLookupAsksPostgreSqlAgain(): void
    {
        $first = $this->ids('wireless mouse for the offic');
        self::assertCount(1, $this->spy->statements(self::LOOKUP));

        $this->engine->resetCaches();
        self::assertSame($first, $this->ids('wireless mouse for the offic'), 'the same answer');

        self::assertCount(2, $this->spy->statements(self::LOOKUP), 'asked again, not answered from the cache');
    }

    public function testResetCachesForgetsTheSynonymStemsExpandersAndDigests(): void
    {
        $index = Indexes::products('manual')->withSynonyms(Synonyms::fromEntries([['tv', 'television']]))->withThresholds(new Thresholds(didYouMean: false));
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->in('products')->query('tv')->get();

        $properties = ['stems', 'expanders', 'emptyAnswers'];
        foreach ($properties as $name) {
            self::assertNotSame([], (new \ReflectionProperty(TextConfigCache::class, $name))->getValue($this->cache()), $name . ' was filled by the search');
        }
        self::assertNotNull((new \ReflectionProperty(TextConfigCache::class, 'synonymDigests'))->getValue($this->cache()));

        $this->engine->resetCaches();

        foreach ($properties as $name) {
            self::assertSame([], (new \ReflectionProperty(TextConfigCache::class, $name))->getValue($this->cache()), $name);
        }
        self::assertNull((new \ReflectionProperty(TextConfigCache::class, 'synonymDigests'))->getValue($this->cache()));
        self::assertSame('(tv OR television)', $fuzzphony->in('products')->query('tv')->get()->interpretedAs, 'and it keeps expanding');
    }

    public function testTheCacheIsBoundedAndStartsOverWhenFull(): void
    {
        $first = $this->ids('wireles mose');
        $property = new \ReflectionProperty(TextConfigCache::class, 'emptyAnswers');
        /** @var array<string, array<string, bool>> $cached */
        $cached = $property->getValue($this->cache());
        $config = (string) array_key_first($cached);
        $filler = static fn(int $count): array => [$config => array_fill_keys(array_map(static fn(int $i): string => 'filler' . $i, range(1, $count)), false)];

        $property->setValue($this->cache(), $filler(5_000));
        $this->ids('wireles mose');
        /** @var array<string, array<string, bool>> $kept */
        $kept = $property->getValue($this->cache());
        self::assertGreaterThanOrEqual(5_000, count($kept[$config]), 'at the limit nothing is dropped');

        $property->setValue($this->cache(), $filler(5_001));
        self::assertSame($first, $this->ids('wireles mose'));
        /** @var array<string, array<string, bool>> $after */
        $after = $property->getValue($this->cache());
        self::assertLessThan(100, count($after[$config]), 'the filler is gone, the answers of this query are back');
    }

    /** The process caches of the engine under test. */
    private function cache(): TextConfigCache
    {
        $caches = (new \ReflectionProperty(PostgresEngine::class, 'caches'))->getValue($this->engine);
        self::assertInstanceOf(TextConfigCache::class, $caches);

        return $caches;
    }
}
