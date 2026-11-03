<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\MatchedWord;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Unit\Core\Search\RecordingSearchLog;
use PHPUnit\Framework\TestCase;

/** The JSON of a result, explainMatches() (what matched, how, and never a hidden field) and logSearch(). */
final class ResultShapeTest extends TestCase
{
    private Connection $connection;
    private RecordingSearchLog $log;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_shape_doc, fuzzphony_shape_docs, fuzzphony_shape_docs__vocab, fuzzphony_shape_docs__next, fuzzphony_shape_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_shape_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, tid int NOT NULL)');
        foreach ([
            [1, 'Wireless mouse', 'a quiet mouse for the desk', 'zebrafish', 1],
            [2, 'Mouses and keyboards', 'the plural of mouse', '', 1],
            [3, 'Mousepad', 'for the mouse', 'mouse secret', 2],
            [4, 'Notebook', 'plain pages', 'mouse', 2],
        ] as [$id, $title, $body, $secret, $tid]) {
            $this->connection->execute('INSERT INTO fz_shape_doc VALUES (:id, :t, :b, :s, :tid)', ['id' => $id, 't' => $title, 'b' => $body, 's' => $secret, 'tid' => $tid]);
        }
        $this->log = new RecordingSearchLog();
    }

    private function fuzzphony(bool $guarded = false, bool $logged = true): Fuzzphony
    {
        $index = IndexDefinition::builder('shape_docs')
            ->fromQuery('SELECT id, title, body, secret, tid FROM fz_shape_doc')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'B')
            ->field('secret', 'C', fuzzy: true, granted: 'ROLE_BOSS')
            ->filter('tid', 'int')
            ->language('english')
            ->sync('manual')
            ->log($logged)
            ->build()
            ->withSynonyms(Synonyms::fromEntries([['desk', 'table'], ['tv', 'telly']]));
        $guard = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return $actor->user === 'boss' ? Access::all() : Access::only(['title', 'body']);
            }
        };
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]), new SearchGate(log: $this->log, guards: $guarded ? [$guard] : []));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('shape_docs');

        return $fuzzphony;
    }

    /** @return array<int|string, list<array{field: string, word: string, via: string, similarity: float|null}>> */
    private static function matchesById(\Fuzzphony\Core\Search\SearchResult $result): array
    {
        $out = [];
        foreach ($result->hits as $hit) {
            self::assertNotNull($hit->matches);
            $out[$hit->id] = array_map(static fn(MatchedWord $m): array => $m->toArray(), $hit->matches);
        }

        return $out;
    }

    public function testAResultIsJsonWithStableKeys(): void
    {
        $result = $this->fuzzphony()->in('shape_docs')->query('mouse')->highlight('title')->facets('tid')->asSystem()->get();
        $data = $result->toArray();

        self::assertEquals($data, json_decode((string) json_encode($result), true), 'json_encode() writes toArray()');
        self::assertSame(['hits', 'total', 'totalIsLowerBound', 'facets', 'didYouMean', 'correctedFrom', 'correctedTo', 'warnings', 'tookMs'], array_keys($data));
        self::assertSame(4, $data['total']);
        self::assertSame(['index', 'kind', 'id', 'score', 'breakdown', 'highlights', 'mergedScore', 'matches'], array_keys($data['hits'][0]));
        self::assertSame('shape_docs', $data['hits'][0]['kind']);
        self::assertSame('shape_docs', $data['hits'][0]['index']);
        self::assertIsInt($data['hits'][0]['id']);
        self::assertNull($data['hits'][0]['matches'], 'not asked for');
        self::assertSame([['value' => 1, 'count' => 2], ['value' => 2, 'count' => 2]], $data['facets']['tid']);
        self::assertStringContainsString('<mark>', implode('', array_map(static fn(Hit $hit): string => $hit->highlights['title'] ?? '', $result->hits)));
    }

    public function testTheMatchesTellWhichWordMatchedWhereAndHow(): void
    {
        $fz = $this->fuzzphony();
        $result = $fz->in('shape_docs')->query('mouse')->explainMatches()->asSystem()->get();
        $matches = self::matchesById($result);

        self::assertContains(['field' => 'title', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null], $matches[1]);
        self::assertContains(['field' => 'body', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null], $matches[1]);
        self::assertContains(['field' => 'title', 'word' => 'mouses', 'via' => 'stem', 'similarity' => null], $matches[2] ?? [], 'another form of the word');
        self::assertContains(['field' => 'secret', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null], $matches[3], 'the system sees the granted field');

        $prefix = self::matchesById($fz->in('shape_docs')->query('mou*')->explainMatches()->asSystem()->get());
        self::assertContains(['field' => 'title', 'word' => 'mousepad', 'via' => 'prefix', 'similarity' => null], $prefix[3]);

        $synonym = self::matchesById($fz->in('shape_docs')->query('table')->explainMatches()->asSystem()->get());
        self::assertSame([['field' => 'body', 'word' => 'desk', 'via' => 'synonym', 'similarity' => null]], $synonym[1]);

        $typo = self::matchesById($fz->in('shape_docs')->query('mousse')->thresholds(['typo' => ['mode' => 'always']])->explainMatches()->asSystem()->get());
        $viaTypo = array_values(array_filter($typo[1], static fn(array $m): bool => $m['via'] === 'typo'));
        self::assertNotSame([], $viaTypo);
        self::assertGreaterThan(0.0, $viaTypo[0]['similarity']);
    }

    public function testMatchesFollowPhrasesFieldScopesExclusionsAndTypoNever(): void
    {
        $fz = $this->fuzzphony();
        $phrase = self::matchesById($fz->in('shape_docs')->query('"wireless mouse"')->explainMatches()->asSystem()->get());
        self::assertContains(['field' => 'title', 'word' => 'wireless', 'via' => 'exact', 'similarity' => null], $phrase[1]);
        self::assertContains(['field' => 'title', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null], $phrase[1]);

        $scoped = self::matchesById($fz->in('shape_docs')->query('title:mouse')->explainMatches()->asSystem()->get());
        self::assertContains(['field' => 'title', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null], $scoped[1]);
        self::assertNotContains('body', array_column($scoped[1], 'field'), 'only the scoped field is looked at');

        $excluded = $fz->in('shape_docs')->query('desk -zebrafish')->explainMatches()->asSystem()->get();
        foreach ($excluded->hits as $hit) {
            self::assertNotContains('zebrafish', array_column(array_map(static fn(MatchedWord $m): array => $m->toArray(), $hit->matches ?? []), 'word'));
        }

        $onlyExclusion = $fz->in('shape_docs')->query('-zebrafish')->explainMatches()->asSystem()->get();
        foreach ($onlyExclusion->hits as $hit) {
            self::assertSame([], $hit->matches, 'nothing was looked for, so nothing matched');
        }

        $off = self::matchesById($fz->in('shape_docs')->query('mouse')->thresholds(['typo' => ['mode' => 'never']])->explainMatches()->asSystem()->get());
        self::assertNotContains('typo', array_column($off[1], 'via'));
    }

    public function testAWordThatMatchesTwiceIsListedOnceWithTheBestWay(): void
    {
        $fz = $this->fuzzphony();
        foreach (['mouse mou*', 'mou* mouse'] as $query) {
            $matches = self::matchesById($fz->in('shape_docs')->query($query)->explainMatches()->asSystem()->get());
            $title = array_values(array_filter($matches[1], static fn(array $m): bool => $m['field'] === 'title' && $m['word'] === 'mouse'));

            self::assertSame([['field' => 'title', 'word' => 'mouse', 'via' => 'exact', 'similarity' => null]], $title, $query);
        }
    }

    /** `tv` is shorter than typo.min_length, so the search typo-matches its synonym `telly` (word_similarity 0.333 >= the flat 0.3): the explanation names that match. */
    public function testAShortWordMatchedByTypoUnderAFlatSimilarityIsExplained(): void
    {
        $this->connection->execute("INSERT INTO fz_shape_doc VALUES (5, 'Tefal kettle', 'boils water', '', 1)");
        $fz = $this->fuzzphony();
        $fz->reindex('shape_docs');
        $result = $fz->in('shape_docs')->query('tv')->thresholds(['typo' => ['mode' => 'always', 'similarity' => 0.3], 'rescue' => ['correct_when_empty' => false]])->explainMatches()->asSystem()->get();
        self::assertSame([5], $result->ids());
        self::assertSame([['field' => 'title', 'word' => 'tefal', 'via' => 'typo', 'similarity' => 0.333]], self::matchesById($result)[5]);
    }

    public function testMatchesAreOnlyComputedForThePageAndOnlyWhenAsked(): void
    {
        $fz = $this->fuzzphony();
        $page = $fz->in('shape_docs')->query('mouse')->limit(2)->explainMatches()->asSystem()->get();
        self::assertCount(2, $page->hits);
        foreach ($page->hits as $hit) {
            self::assertNotNull($hit->matches);
        }
        $plain = $fz->in('shape_docs')->query('mouse')->asSystem()->get();
        foreach ($plain->hits as $hit) {
            self::assertNull($hit->matches);
        }
        // the search statements are the same with and without it
        self::assertSame(
            $fz->in('shape_docs')->query('mouse')->asSystem()->explain()->statements,
            $fz->in('shape_docs')->query('mouse')->explainMatches()->asSystem()->explain()->statements,
        );
        self::assertSame($plain->ids(), $fz->in('shape_docs')->query('mouse')->explainMatches()->asSystem()->get()->ids(), 'and the hits');
    }

    public function testARestrictedViewerLearnsNothingOfAHiddenField(): void
    {
        $fz = $this->fuzzphony(guarded: true);
        $ada = $fz->in('shape_docs')->query('mouse')->asActor(new Actor(user: 'ada'))->explainMatches()->get();
        $matches = self::matchesById($ada);

        $ids = $ada->ids();
        sort($ids);
        self::assertSame([1, 2, 3], $ids, 'document 4 has "mouse" in its hidden field only');
        foreach ($matches as $id => $words) {
            foreach ($words as $match) {
                self::assertNotSame('secret', $match['field'], "document $id");
            }
        }
        self::assertStringNotContainsString('zebrafish', (string) json_encode($ada));
        self::assertStringNotContainsString('secret', (string) json_encode($ada));

        $typo = $fz->in('shape_docs')->query('zebrafsh')->thresholds(['typo' => ['mode' => 'always']])->asActor(new Actor(user: 'ada'))->explainMatches()->get();
        self::assertSame([], $typo->ids());
        self::assertSame([1], $fz->in('shape_docs')->query('zebrafish')->asActor(new Actor(user: 'boss'))->explainMatches()->get()->ids(), 'the role holder does find it');
        $scoped = $fz->in('shape_docs')->query('secret:mouse')->asActor(new Actor(user: 'ada'))->explainMatches()->get();
        self::assertSame([], $scoped->ids());
    }

    public function testLogSearchRecordsWithoutSearching(): void
    {
        $fz = $this->fuzzphony();
        $logged = $fz->logSearch('shape_docs', 'wireless mouse', 12, 3.5, new Actor(user: 'ada'), rescued: true);

        self::assertSame('wireless mouse', $logged->query);
        self::assertSame(12, $logged->hits);
        self::assertSame('ada', $logged->user);
        self::assertTrue($logged->rescued);
        self::assertSame(RecordStatus::Ok, $logged->status);
        self::assertCount(1, $this->log->records);
        self::assertSame('wireless mouse', $this->log->records[0]->query);

        $fz->logSearch('shape_docs', 'job', 0, system: true);
        self::assertSame('(system)', $this->log->records[1]->user);
    }

    public function testLogSearchRefusesWhereASearchIsNotRecorded(): void
    {
        $unlogged = $this->fuzzphony(logged: false);
        try {
            $unlogged->logSearch('shape_docs', 'x', 1);
            self::fail('InvalidArgument expected');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('not logged', $e->getMessage());
        }
        self::assertSame([], $this->log->records);

        $noLog = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([IndexDefinition::builder('shape_docs')->fromQuery('SELECT id, title FROM fz_shape_doc')->field('title', 'A')->sync('manual')->build()]));
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('search log is off');
        $noLog->logSearch('shape_docs', 'x', 1);
    }

    public function testLogSearchOnATenantScopedIndexNeedsATenant(): void
    {
        $index = IndexDefinition::builder('shape_docs')->fromQuery('SELECT id, title, tid FROM fz_shape_doc')->field('title', 'A')->filter('tid', 'int')->tenant('tid')->sync('manual')->build();
        $fz = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]), new SearchGate(log: $this->log));

        self::assertSame('2', $fz->logSearch('shape_docs', 'x', 1, actor: new Actor(tenant: 2))->tenant);
        $this->expectException(ActorRequired::class);
        $fz->logSearch('shape_docs', 'x', 1);
    }
}
