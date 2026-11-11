<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * many: { rank: best }: a field with several values ranks a document by its best single value (spec 0.12 section 5). Two indexes over the
 * same customers: `bcust` (rank: best) and `wcust` (rank: whole, the 0.11 rank), so every test can compare with the whole-field rank.
 */
final class ManyBestRankTest extends TestCase
{
    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute('CREATE TABLE mb_customer (id bigint PRIMARY KEY, name text NOT NULL, note text NOT NULL DEFAULT \'\', tid integer NOT NULL DEFAULT 1)');
        $this->connection->execute('CREATE TABLE mb_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, line text)');
        $this->connection->execute('CREATE INDEX mb_address_customer ON mb_address (customer_id)');
        $this->connection->execute("INSERT INTO mb_customer (id, name) VALUES (1, 'Kiss Anna'), (2, 'Nagy Béla'), (3, 'Tóth Ede'), (4, 'Szeged Kft'), (5, 'Tóth Edit')");
        $this->connection->execute("INSERT INTO mb_address (customer_id, line) VALUES (1, 'Budapest, Fő utca 1'), (1, 'Szeged, Kossuth tér 2'), (2, 'Szeged, Fő utca 5'),
            (3, 'Budapest, Fő utca 7'), (3, 'Budapest, Fő utca 9'), (4, 'Pécs, Fő tér 1'), (5, 'Budapest, Fő utca 7')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $tables = [];
        foreach (['bcust', 'wcust', 'other'] as $index) {
            array_push($tables, 'fuzzphony_' . $index, 'fuzzphony_' . $index . '__next', 'fuzzphony_' . $index . '__changes', 'fuzzphony_' . $index . '__vocab', 'fuzzphony_' . $index . '__values');
        }
        $this->connection->execute('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ', mb_address, mb_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    private static function definition(string $name, string $rank, ?string $granted = null, int $limit = 50, bool $note = false): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)->fromTable('mb_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('address', 'C', fuzzy: true, granted: $granted, many: ['from' => 'mb_address', 'key' => 'customer_id', 'column' => 'line', 'limit' => $limit, 'rank' => $rank]);
        if ($note) {
            $builder->field('note', 'B', granted: 'ROLE_NOTE');
        }

        return $builder->sync('manual')->build();
    }

    /** @param list<IndexDefinition> $indexes */
    private function install(array $indexes): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry($indexes));
        $fuzzphony->schema()->apply($this->connection);
        foreach ($indexes as $index) {
            $fuzzphony->reindex($index->name);
        }

        return $fuzzphony;
    }

    private function both(?string $granted = null, int $limit = 50): Fuzzphony
    {
        return $this->install([self::definition('bcust', 'best', $granted, $limit), self::definition('wcust', 'whole', $granted, $limit)]);
    }

    private static function strict(SearchBuilder $search): SearchBuilder
    {
        return $search->thresholds(['typo' => ['mode' => 'never']]);
    }

    /** @return list<int|string> the ids in result order */
    private static function order(SearchResult $result): array
    {
        return array_map(static fn($h): int|string => $h->id, $result->hits);
    }

    /** @return array<int|string, float> id => text rank */
    private static function ranks(SearchResult $result): array
    {
        $out = [];
        foreach ($result->hits as $hit) {
            $out[$hit->id] = round($hit->breakdown->textRank, 9);
        }

        return $out;
    }

    public function testTheSingleValueMatchRanksAboveTheSpreadOneWhichKeepsItsWholeFieldRank(): void
    {
        $f = $this->both();

        $best = self::strict($f->in('bcust')->query('fo utca szeged'))->explainMatches()->get();
        $whole = self::strict($f->in('wcust')->query('fo utca szeged'))->get();
        self::assertSame([2, 1], self::order($best), 'both customers in the default mode, the one-address match first');
        self::assertSame(self::ranks($whole)[1], self::ranks($best)[1], 'the words spread over two addresses: the 0.11 rank (no extra penalty)');
        self::assertGreaterThan(self::ranks($whole)[2], self::ranks($best)[2] + 1e-12, 'the one-address match is ranked on its value');
        self::assertSame(['address' => 0], $best->hits[0]->breakdown->bestValues);
        self::assertSame([], $best->hits[1]->breakdown->bestValues, 'no single value holds a cover: no best value');
    }

    public function testTheBestValueCountsNotTheSumOfSeveralMatchingValues(): void
    {
        $f = $this->both();

        $best = self::strict($f->in('bcust')->query('fo utca budapest'))->explainMatches()->get();
        self::assertSame([1, 3, 5], self::order($best), 'equal ranks, ordered by id');
        $ranks = self::ranks($best);
        self::assertSame($ranks[1], $ranks[3], 'two matching addresses rank like one');
        self::assertSame($ranks[1], $ranks[5]);
        $phrase = self::ranks(self::strict($f->in('bcust')->query('"fo utca"'))->get());
        self::assertSame($phrase[5], $phrase[3], 'a phrase: two matching addresses rank like one');
        self::assertSame(['address' => 0], $best->hits[0]->breakdown->bestValues, 'the first address of customer 1 (positions from 0)');
        self::assertSame(['address' => 0], $best->hits[1]->breakdown->bestValues, 'of two equal values the first');

        $whole = self::strict($f->in('wcust')->query('fo utca budapest'))->get();
        self::assertSame(3, $whole->hits[0]->id, 'the whole-field rank counts both covers of customer 3');
        self::assertGreaterThan(self::ranks($whole)[5], self::ranks($whole)[3]);
    }

    public function testCrossFieldCoversAndTheFallback(): void
    {
        $f = $this->both();

        $kiss = self::strict($f->in('bcust')->query('kiss szeged'))->explainMatches()->highlight('address')->get();
        self::assertSame([1], self::order($kiss));
        self::assertSame(['address' => 1], $kiss->hits[0]->breakdown->bestValues, 'name + the Szeged address');
        self::assertSame('<mark>Szeged</mark>, Kossuth tér 2', $kiss->hits[0]->highlights['address'] ?? null, 'the value the hit was ranked by');

        $kft = self::strict($f->in('bcust')->query('szeged fo ter'))->explainMatches()->get();
        self::assertSame([4, 1], self::order($kft), 'name + one value first; customer 1 has the words spread over its addresses');
        self::assertSame(['address' => 0], $kft->hits[0]->breakdown->bestValues);
        self::assertSame([], $kft->hits[1]->breakdown->bestValues);

        foreach (['kossuth fo', 'kiss anna', 'nagy', 'kiss OR nagy', 'address:szeged', 'tóth -edit'] as $text) {
            self::assertSame(
                self::ranks(self::strict($f->in('wcust')->query($text))->get()),
                self::ranks(self::strict($f->in('bcust')->query($text))->get()),
                sprintf('"%s": no single value gives a better cover, or the query does not touch the field: the whole-field rank', $text),
            );
        }
    }

    public function testTypoAndAsYouType(): void
    {
        $f = $this->both();

        $typo = $f->in('bcust')->query('fo utca szgeed')->thresholds(['typo' => ['mode' => 'always']])->get();
        $wholeTypo = $f->in('wcust')->query('fo utca szgeed')->thresholds(['typo' => ['mode' => 'always']])->get();
        $ids = self::order($typo);
        sort($ids);
        self::assertSame([1, 2], $ids);
        self::assertSame(
            array_map(static fn($h): array => [$h->id, round($h->score, 9)], $wholeTypo->hits),
            array_map(static fn($h): array => [$h->id, round($h->score, 9)], $typo->hits),
            'a typo match is scored on the joined text (r_fuzzy unchanged), the strict word misses',
        );

        $prefix = self::strict($f->in('bcust')->query('fo utca sze')->asYouType())->explainMatches()->get();
        self::assertSame([2, 1], self::order($prefix));
        self::assertSame(['address' => 0], $prefix->hits[0]->breakdown->bestValues);
    }

    public function testTheHighlightAndTheBestValueStayOffUnlessAsked(): void
    {
        $f = $this->both();

        $plain = self::strict($f->in('bcust')->query('fo utca szeged'))->explain();
        self::assertStringNotContainsString('bv_address', $plain->statements[0]['sql']);
        self::assertSame([], $plain->result->hits[0]->breakdown->bestValues);
        $marked = self::strict($f->in('bcust')->query('fo utca szeged'))->highlight('address')->explain();
        self::assertStringContainsString('bv_address', $marked->statements[0]['sql']);
        self::assertSame([], $marked->result->hits[0]->breakdown->bestValues, 'reported only with explainMatches()');
        self::assertSame('Szeged, <mark>Fő</mark> <mark>utca</mark> 5', str_replace('<mark>Szeged</mark>', 'Szeged', $marked->result->hits[0]->highlights['address'] ?? ''));
    }

    public function testFederatedRanksByTheBestValue(): void
    {
        $f = $this->install([self::definition('bcust', 'best'), IndexDefinition::builder('other')->fromTable('mb_customer')->field('name', 'A')->sync('manual')->build()]);

        $hits = iterator_to_array($f->federated()->index('bcust')->index('other')->query('fo utca szeged')->get(), false);
        self::assertSame([2, 1], array_map(static fn($h): int|string => $h->id, array_values(array_filter($hits, static fn($h): bool => $h->index === 'bcust'))));
    }

    public function testValuesPastTheLimitTakeNoPart(): void
    {
        $this->connection->execute("INSERT INTO mb_address (customer_id, line) VALUES (2, 'Zala utca 3')");
        $f = $this->both(limit: 1);

        self::assertSame([], self::order(self::strict($f->in('bcust')->query('zala'))->get()), 'the second value (by order) is past the limit');
        $best = self::strict($f->in('bcust')->query('fo utca szeged'))->explainMatches()->get();
        self::assertSame([2], self::order($best), 'customer 1 keeps only its first address (Budapest): Szeged is past the limit');
        self::assertSame(['address' => 0], $best->hits[0]->breakdown->bestValues);
    }

    public function testAHiddenBestFieldChangesNothingForARestrictedViewer(): void
    {
        $f = $this->both('ROLE_ADDRESS');

        $outcomes = [];
        foreach (['none' => [], 'one' => ['Budapest'], 'matching' => ['Kiss Anna', 'Kiss', 'Szeged Kft', 'Nagy Béla Szeged'], 'many' => array_map(static fn(int $i): string => 'Kiss utca ' . $i, range(1, 60))] as $variant => $lines) {
            $this->connection->execute('DELETE FROM mb_address');
            foreach ($lines as $line) {
                $this->connection->execute('INSERT INTO mb_address (customer_id, line) VALUES (1, :l), (4, :l)', ['l' => $line]);
            }
            $f->reindex('bcust');
            $f->reindex('wcust');
            foreach (['kiss', 'kiss OR nagy', 'szeged', 'kiss anna', 'kis', 'name:kiss', 'kiss -nagy', 'szeged kft', 'kis*'] as $text) {
                foreach (['bcust', 'wcust'] as $index) {
                    $explained = $f->in($index)->query($text)->restrictedToFields('name')->explainMatches()->highlight('name', 'address')->facets()->explain();
                    $result = $explained->result;
                    $outcomes[$text][$variant . '/' . $index] = [
                        array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->breakdown->bestValues, $hit->highlights, array_map(static fn($m): array => $m->toArray(), ($hit->matches ?? []))], $result->hits),
                        $result->total,
                        $result->didYouMean,
                        array_map(static fn(array $st): array => [$st['label'], str_replace('wcust', 'bcust', $st['sql']), $st['params']], $explained->statements),
                    ];
                }
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['none/bcust'] ?? null, $outcome, sprintf('"%s": the hidden values (%s) changed the outcome', $text, $variant));
            }
        }
    }

    public function testAHiddenOtherFieldChangesNothingForAViewerOfTheBestField(): void
    {
        $f = $this->install([self::definition('bcust', 'best', note: true)]);

        $outcomes = [];
        foreach (['empty' => '', 'words' => 'Szeged fo utca Kiss Kossuth', 'long' => str_repeat('szeged utca ', 400)] as $variant => $note) {
            $this->connection->execute('UPDATE mb_customer SET note = :n', ['n' => $note]);
            $f->reindex('bcust');
            foreach (['fo utca szeged', 'kiss szeged', 'fo utca budapest', 'szeged', 'fo utca sze*'] as $text) {
                $explained = $f->in('bcust')->query($text)->restrictedToFields('name', 'address')->explainMatches()->highlight('address')->explain();
                $outcomes[$text][$variant] = [
                    array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->breakdown->bestValues, $hit->highlights], $explained->result->hits),
                    $explained->result->total,
                    array_map(static fn(array $st): array => [$st['label'], $st['sql'], $st['params']], $explained->statements),
                ];
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'] ?? null, $outcome, sprintf('"%s": the hidden note (%s) changed the outcome', $text, $variant));
            }
        }
        // and the viewer of the address field gets the best-value rank
        self::assertSame([2, 1], self::order($f->in('bcust')->query('fo utca szeged')->restrictedToFields('name', 'address')->thresholds(['typo' => ['mode' => 'never']])->get()));
    }
}
