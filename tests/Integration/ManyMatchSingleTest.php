<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * manyMatch('single'): ONE value of a `many: { rank: best }` field must hold every word the document needs from that field (spec 0.12
 * section 5.3). Customer 1 has the addresses "Budapest, Fő utca 1" and "Szeged, Kossuth tér 2", customer 2 the single "Fő utca, Szeged":
 * "fo utca szeged" finds both by default, only customer 2 in the single-value mode.
 */
final class ManyMatchSingleTest extends TestCase
{
    /** testOnlyTheValuesThatCanHoldAWordAreReadTheResultsStay(): the results before the prefilter */
    private const RECORDED = [[1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [2, 3], [2, 3], [3], [3], [3], [3], [2], [2], [2], [2], [2], [2], [1], [1], [1], [1], [1], [1],
        [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 3], [1, 3], [1, 3], [1, 3], [1, 3], [1, 3],
        [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2, 3], [1, 2, 3], [1, 2, 3],
        [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2, 3], [1, 2], [1, 2, 3], [1, 2], [1, 2, 3], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [1, 2], [2], [2],
        [2], [2], [2], [2], [1], [1], [1], [1], [1], [1]];


    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute("CREATE TABLE ms_customer (id bigint PRIMARY KEY, name text NOT NULL, note text NOT NULL DEFAULT '', tid integer NOT NULL DEFAULT 1, kind text NOT NULL DEFAULT 'person')");
        $this->connection->execute('CREATE TABLE ms_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, line text)');
        $this->connection->execute('CREATE TABLE ms_tag (id serial PRIMARY KEY, customer_id bigint NOT NULL, tag text)');
        $this->connection->execute("INSERT INTO ms_customer (id, name, tid, kind) VALUES (1, 'Kiss Anna', 1, 'person'), (2, 'Nagy Béla', 1, 'person'), (3, 'Tóth Ede', 1, 'person'),
            (4, 'Szeged Kft', 1, 'company')");
        $this->connection->execute("INSERT INTO ms_address (customer_id, line) VALUES (1, 'Budapest, Fő utca 1'), (1, 'Szeged, Kossuth tér 2'), (2, 'Fő utca, Szeged'),
            (3, 'Budapest, Fő utca 7'), (3, 'Budapest, Fő utca 9'), (4, 'Pécs, Fő tér 1')");
        $this->connection->execute("INSERT INTO ms_tag (customer_id, tag) VALUES (2, 'red car'), (2, 'blue bike'), (3, 'red bike')");
        $this->connection->execute('CREATE TABLE ms_label (id serial PRIMARY KEY, customer_id bigint NOT NULL, label text)');
        $this->connection->execute("INSERT INTO ms_label (customer_id, label) VALUES (1, 'red szeged'), (1, 'utca blue'), (2, 'fo label'), (3, 'blue utca'), (3, 'kossuth red')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $tables = [];
        foreach (['scust', 'tcust', 'other'] as $index) {
            array_push($tables, 'fuzzphony_' . $index, 'fuzzphony_' . $index . '__next', 'fuzzphony_' . $index . '__changes', 'fuzzphony_' . $index . '__vocab', 'fuzzphony_' . $index . '__values');
        }
        $this->connection->execute('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ', ms_label, ms_tag, ms_address, ms_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    /** @param int $labels how many more rank: best fields over ms_label (label, label2, ...) */
    private static function definition(string $name = 'scust', ?string $granted = null, int $limit = 50, bool $note = false, bool $tenant = false, int $labels = 0): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)->fromTable('ms_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('address', 'C', fuzzy: true, granted: $granted, many: ['from' => 'ms_address', 'key' => 'customer_id', 'column' => 'line', 'limit' => $limit, 'rank' => 'best'])
            ->field('tag', 'D', many: ['from' => 'ms_tag', 'key' => 'customer_id', 'column' => 'tag', 'rank' => 'best']);
        for ($i = 1; $i <= $labels; ++$i) {
            $builder->field($i === 1 ? 'label' : 'label' . $i, 'D', fuzzy: true, many: ['from' => 'ms_label', 'key' => 'customer_id', 'column' => 'label', 'rank' => 'best']);
        }
        $builder
            ->filter('kind', 'string')
            ->filter('tid', 'int');
        if ($note) {
            $builder->field('note', 'B', granted: 'ROLE_NOTE');
        }
        if ($tenant) {
            $builder->tenant('tid');
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

    private static function exact(SearchBuilder $search): SearchBuilder
    {
        return $search->thresholds(['typo' => ['mode' => 'never']]);
    }

    /** @return list<int|string> the ids, sorted */
    private static function ids(SearchResult $result): array
    {
        $ids = array_map(static fn($h): int|string => $h->id, $result->hits);
        sort($ids);

        return $ids;
    }

    /** @return list<int|string> */
    private function single(Fuzzphony $f, string $text, string ...$fields): array
    {
        return self::ids(self::exact($f->in('scust')->query($text))->manyMatch('single', ...$fields)->get());
    }

    public function testOneValueMustHoldTheWordsOfTheField(): void
    {
        $f = $this->install([self::definition()]);

        self::assertSame([1, 2], self::ids(self::exact($f->in('scust')->query('fo utca szeged'))->get()), 'default: the words may be spread over the values');
        $strict = self::exact($f->in('scust')->query('fo utca szeged'))->manyMatch('single')->get();
        self::assertSame([2], self::ids($strict), 'single: customer 1 has fo utca and szeged in two addresses');
        self::assertSame(1, $strict->total);
        self::assertSame([1, 3], $this->single($f, 'fo utca budapest'));
        self::assertSame([1], $this->single($f, 'kiss szeged'), 'a word of another field + one value');
        self::assertSame([4], $this->single($f, 'szeged fo ter'), 'the name holds szeged, one value the rest');
        self::assertSame([], $this->single($f, 'kossuth fo'));
        self::assertSame([2], $this->single($f, '"fo utca" szeged'), 'a phrase');
        self::assertSame([1, 2, 3], $this->single($f, 'kiss OR "fo utca"'));
        self::assertSame([1, 2], $this->single($f, 'nagy OR (kossuth szeged)'));
        self::assertSame([2], $this->single($f, 'address:szeged address:fo'), 'words scoped to the field');
        self::assertSame([], $this->single($f, 'address:kossuth address:fo'));
    }

    public function testWithoutTheValueCountsTheValuesAreRead(): void
    {
        $f = $this->install([self::definition()]);
        $texts = ['fo utca szeged', 'fo utca budapest', 'kiss szeged', 'szeged fo ter', 'kossuth fo', 'red bike', 'fo utca sze*'];
        $with = array_map(fn(string $text): array => $this->single($f, $text), $texts);

        $this->connection->execute('UPDATE fuzzphony_scust SET n_address = NULL, n_tag = NULL');
        self::assertSame($with, array_map(fn(string $text): array => $this->single($f, $text), $texts), 'a document with one value passes on the count or on its value alike');
        $this->connection->execute('UPDATE fuzzphony_scust SET n_address = 5, n_tag = 5');
        self::assertSame($with, array_map(fn(string $text): array => $this->single($f, $text), $texts));
    }

    public function testATypoWordOverAFieldBoundaryIsHeldByNoValue(): void
    {
        $this->connection->execute("INSERT INTO ms_customer (id, name) VALUES (5, 'Kiss'), (6, 'Kiss')");
        $this->connection->execute("INSERT INTO ms_address (customer_id, line) VALUES (5, 'Anna utca 1'), (6, 'Anna utca 1'), (6, 'Pécs tér 9')");
        $f = $this->install([self::definition()]);
        $always = static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['typo' => ['mode' => 'always']]);
        $default = self::ids($always($f->in('scust')->query('kisanna'))->get());
        self::assertContains(5, $default, 'the default typo match reads every field joined');
        self::assertContains(6, $default);

        $strict = static fn(): array => array_values(array_intersect([5, 6], self::ids($always($f->in('scust')->query('kisanna'))->manyMatch('single')->get())));
        self::assertSame([], $strict(), 'no one field holds the word: whatever the number of values');
        $this->connection->execute('UPDATE fuzzphony_scust SET n_address = NULL, n_tag = NULL');
        self::assertSame([], $strict());
    }

    public function testTheValueCountsNeverChangeTheResult(): void
    {
        $this->connection->execute("INSERT INTO ms_customer (id, name) VALUES (5, 'Kiss'), (6, 'Kiss')");
        $this->connection->execute("INSERT INTO ms_address (customer_id, line) VALUES (5, 'Anna utca 1'), (6, 'Anna utca 1'), (6, 'Pécs tér 9')");
        $f = $this->install([self::definition()]);
        $texts = ['kisanna', 'fo utca szeged', 'fo utca szgeed', 'kiss anna', 'kis ana', 'kiss szeged', '"kiss anna"', 'anna utca', 'nagy blue', 'fo utca sze*', 'red bkie'];
        $run = function () use ($f, $texts): array {
            $out = [];
            foreach ($texts as $text) {
                foreach (['always', 'fallback', 'never'] as $mode) {
                    foreach ([null, ['name', 'address'], ['name', 'address', 'tag']] as $visible) {
                        $search = $f->in('scust')->query($text)->thresholds(['typo' => ['mode' => $mode]])->manyMatch('single');
                        $result = ($visible === null ? $search : $search->restrictedToFields(...$visible))->get();
                        $out[sprintf('%s %s %s', $text, $mode, json_encode($visible))] = [array_map(static fn($h): array => [$h->id, round($h->score, 9)], $result->hits), $result->total];
                    }
                }
            }

            return $out;
        };
        $filled = $run();
        $this->connection->execute('UPDATE fuzzphony_scust SET n_address = NULL, n_tag = NULL');
        self::assertSame($filled, $run(), 'the counts filled or not: the same documents, scores and totals');
    }

    /**
     * The values of a named field are read only when they can hold a word of the query (the prefilter): the results are those of the
     * statement that read every value, recorded before the prefilter, for one, two and three named fields, exact and typo-tolerant.
     */
    public function testOnlyTheValuesThatCanHoldAWordAreReadTheResultsStay(): void
    {
        $f = $this->install([self::definition(labels: 1)]);
        $texts = ['fo utca szeged', 'red bike', 'fo utca szeged blue bike', 'kossuth szeged red', 'szeged red', '"fo utca" szeged', 'fo utca budapest red',
            'kiss OR "fo utca" bike', 'address:szeged red', 'blue utca', 'szgeed red bkie', 'fo utca sze*', 'utca blue -kossuth', '"utca blue" kiss'];
        $out = [];
        foreach ($texts as $text) {
            foreach ([['address'], ['address', 'tag'], ['address', 'tag', 'label']] as $fields) {
                foreach (['never', 'always'] as $mode) {
                    $out[] = self::ids($f->in('scust')->query($text)->thresholds(['typo' => ['mode' => $mode]])->manyMatch('single', ...$fields)->get());
                }
            }
        }
        self::assertSame(self::RECORDED, $out);
    }

    /**
     * 300 documents of 30 addresses (`alpha street n` / `beta road n`) and 30 tags that hold no word of the query: no address holds both
     * words, so every document reads its values. Before the prefilter a second named field multiplied the rows by its values (31 x 31
     * per document); now the tags that hold no word are not read and the cost stays near the one of the address alone.
     */
    public function testASecondFieldWhoseValuesHoldNoWordCostsNearlyNothing(): void
    {
        $this->connection->execute("INSERT INTO ms_customer (id, name) SELECT g, 'Kiss' FROM generate_series(100, 399) g");
        $this->connection->execute("INSERT INTO ms_address (customer_id, line) SELECT g, CASE WHEN i % 2 = 0 THEN 'alpha street ' || i ELSE 'beta road ' || i END FROM generate_series(100, 399) g, generate_series(1, 30) i");
        $this->connection->execute("INSERT INTO ms_tag (customer_id, tag) SELECT g, 'gamma tag ' || i FROM generate_series(100, 399) g, generate_series(1, 30) i");
        $f = $this->install([self::definition()]);
        $this->connection->execute('ANALYZE fuzzphony_scust, fuzzphony_scust__values');
        $time = function (string ...$fields) use ($f): float {
            $runs = [];
            for ($i = 0; $i < 5; ++$i) {
                $start = hrtime(true);
                $result = self::exact($f->in('scust')->query('alpha beta'))->exactCounts()->manyMatch('single', ...$fields)->get();
                $runs[] = (hrtime(true) - $start) / 1e6;
                self::assertSame(0, $result->total);
            }
            sort($runs);

            return $runs[2];
        };
        $time('address');
        $one = $time('address');
        $two = $time('address', 'tag');
        self::assertLessThan(max(4 * $one, 50.0), $two, sprintf('address %.1f ms, address + tag %.1f ms: the tags that hold no word are not read', $one, $two));
    }

    public function testAtMostThreeFieldsAreCheckedTogether(): void
    {
        $f = $this->install([self::definition(granted: 'ROLE_ADDRESS', labels: 2)]);

        try {
            $this->single($f, 'fo utca');
            self::fail('expected InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame("manyMatch(): at most 3 fields can be checked together, 4 would be (address, tag, label, label2); name the ones that must hold the words in one value: manyMatch('single', ...).", $e->getMessage());
        }
        self::assertSame([1, 2], $this->single($f, 'utca szeged', 'address', 'label'));
        // a viewer who cannot see the address has three such fields: the hidden one never counts
        self::assertSame([2], self::ids(self::exact($f->in('scust')->query('fo label'))->restrictedToFields('name', 'tag', 'label', 'label2')->manyMatch('single')->get()));
    }

    public function testAnExcludedWordExcludesTheDocumentWhenAnyValueHoldsIt(): void
    {
        $f = $this->install([self::definition()]);

        self::assertSame([3], $this->single($f, 'fo utca -szeged'), 'customer 1 has szeged in its other address: excluded (whole document)');
        self::assertSame([2, 3], $this->single($f, 'fo utca -kossuth'));
        self::assertSame([2, 3], self::ids(self::exact($f->in('scust')->query('fo utca -kossuth'))->get()), 'as in the default mode');
        self::assertSame([2], $this->single($f, 'fo utca -address:budapest'));
    }

    public function testTheFieldList(): void
    {
        $f = $this->install([self::definition()]);

        self::assertSame([2, 3], self::ids(self::exact($f->in('scust')->query('red bike'))->get()));
        self::assertSame([3], $this->single($f, 'red bike'), 'every visible rank: best field: tag too');
        self::assertSame([3], $this->single($f, 'red bike', 'tag'));
        self::assertSame([2, 3], $this->single($f, 'red bike', 'address'), 'only the address must be one value');
        self::assertSame([2], $this->single($f, 'fo utca szeged blue bike', 'address', 'tag'), 'one address and one tag');
        self::assertSame([], $this->single($f, 'fo utca szeged red bike', 'address', 'tag'));
        self::assertSame([2], $this->single($f, 'fo utca szeged red bike', 'address'));
        self::assertSame([], $this->single($f, 'fo utca szeged red bike'));

        try {
            $this->single($f, 'red', 'name');
            self::fail('expected InvalidQuery');
        } catch (InvalidQuery $e) {
            self::assertSame('manyMatch(): field "name" is not a many field with rank: best. Fields with it: address, tag.', $e->getMessage());
        }
    }

    public function testTypoToleranceAndAsYouTypeWithinOneValue(): void
    {
        $f = $this->install([self::definition()]);
        $always = static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['typo' => ['mode' => 'always']]);

        self::assertSame([1, 2], self::ids($always($f->in('scust')->query('fo utca szgeed'))->get()));
        self::assertSame([2], self::ids($always($f->in('scust')->query('fo utca szgeed'))->manyMatch('single')->get()), 'the typo word in the same value');
        self::assertSame([1, 3], self::ids($always($f->in('scust')->query('fo utca budapst'))->manyMatch('single')->get()));
        self::assertSame([2], self::ids($f->in('scust')->query('fo utca szgeed')->manyMatch('single')->get()), 'the fallback to typo tolerance');
        self::assertSame([2], self::ids($f->in('scust')->query('fo utca szgeed')->manyMatch('single', 'address')->get()));

        self::assertSame([1, 2], self::ids(self::exact($f->in('scust')->query('fo utca sze'))->asYouType()->get()));
        self::assertSame([2], self::ids(self::exact($f->in('scust')->query('fo utca sze'))->asYouType()->manyMatch('single')->get()));
        self::assertSame([2], self::ids($f->in('scust')->query('fo utca sze')->asYouType()->manyMatch('single')->get()));
    }

    public function testTheStatementsAndTheMatchExplanation(): void
    {
        $f = $this->install([self::definition()]);

        $plain = $f->in('scust')->query('fo utca szgeed')->thresholds(['typo' => ['mode' => 'always']])->explain();
        $explained = $f->in('scust')->query('fo utca szgeed')->thresholds(['typo' => ['mode' => 'always']])->manyMatch('single')->explain();
        $sql = implode("\n", array_column($explained->statements, 'sql'));
        $exists = 'EXISTS (SELECT 1 FROM (SELECT \'\'::tsvector AS t, \'\'::text AS z UNION ALL SELECT v.t, v.z FROM "public"."fuzzphony_scust__values" AS v WHERE v.doc = s.id AND v.field = \'address\'';
        self::assertSame(2, substr_count($sql, '(s."n_address" <= 1 AND s."n_tag" <= 1 OR ' . $exists), 'the full-text branch (its fast and ordered selections): a document with one value passes on the count');
        self::assertSame(3, substr_count($sql, $exists), 'and the typo branch, without the count: the address field');
        self::assertSame(3, preg_match_all("/v\\.field = 'tag' AND \\([^()]*\\)\\) AS v2/", $sql), 'and the tag field, in the same EXISTS, each read only where a value holds a word');
        self::assertStringNotContainsString('UNION ALL SELECT v.t, v.z', implode("\n", array_column($plain->statements, 'sql')));

        $result = self::exact($f->in('scust')->query('kossuth szeged'))->manyMatch('single')->explainMatches()->highlight('address')->get();
        self::assertSame([1], self::ids($result));
        self::assertSame(['address' => 1], $result->hits[0]->breakdown->bestValues);
        self::assertSame('<mark>Szeged</mark>, <mark>Kossuth</mark> tér 2', $result->hits[0]->highlights['address'] ?? null);
        self::assertNotSame([], $result->hits[0]->matches ?? []);
    }

    public function testValuesPastTheLimitTakeNoPart(): void
    {
        $f = $this->install([self::definition(limit: 1)]);

        self::assertNotContains(1, $this->single($f, 'kossuth szeged'), 'the second address of customer 1 is past the limit');
        self::assertSame([1, 3], $this->single($f, 'fo utca budapest'));
    }

    public function testAViewerWhoCannotSeeTheFieldGetsTheSameSearchWhatTheValuesHold(): void
    {
        $f = $this->install([self::definition(granted: 'ROLE_ADDRESS')]);

        $outcomes = [];
        foreach (['none' => [], 'one' => ['Budapest'], 'matching' => ['Kiss Anna', 'Kiss', 'Szeged Kft', 'Nagy Béla Szeged'], 'many' => array_map(static fn(int $i): string => 'Kiss utca ' . $i, range(1, 60))] as $variant => $lines) {
            $this->connection->execute('DELETE FROM ms_address');
            foreach ($lines as $line) {
                $this->connection->execute('INSERT INTO ms_address (customer_id, line) VALUES (1, :l), (4, :l)', ['l' => $line]);
            }
            $f->reindex('scust');
            foreach (['kiss', 'kiss OR nagy', 'szeged', 'kiss anna', 'kis', 'kiss -nagy', 'szeged kft', 'kis*', 'red bike'] as $text) {
                $modes = ['plain' => [['name'], null], 'single' => [['name'], []], 'tag viewer' => [['name', 'tag'], []], 'tag viewer, tag' => [['name', 'tag'], ['tag']]];
                foreach ($modes as $mode => [$visible, $fields]) {
                    $search = $f->in('scust')->query($text)->restrictedToFields(...$visible)->explainMatches()->highlight('name', 'address')->facets('kind');
                    $explained = ($fields === null ? $search : $search->manyMatch('single', ...$fields))->explain();
                    $result = $explained->result;
                    $outcomes[$text . ' ' . $mode][$variant] = [
                        array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->breakdown->bestValues, $hit->highlights, array_map(static fn($m): array => $m->toArray(), ($hit->matches ?? []))], $result->hits),
                        $result->total,
                        $result->didYouMean,
                        array_map(static fn($v): array => [$v->value, $v->count], $result->facets['kind'] ?? []),
                        array_map(static fn(array $st): array => [$st['label'], $st['sql'], $st['params']], $explained->statements),
                    ];
                    if ($mode === 'single' && $variant === 'none') {
                        self::assertSame($outcomes[$text . ' plain']['none'], $outcomes[$text . ' single']['none'], 'no visible field of the address: the default search');
                    }
                }
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['none'] ?? null, $outcome, sprintf('"%s": the hidden values (%s) changed the outcome', $text, $variant));
            }
        }

        // a hidden name is refused like an unknown one, listing the visible names only
        foreach (['address', 'nosuch'] as $name) {
            try {
                $f->in('scust')->query('kiss')->restrictedToFields('name', 'tag')->manyMatch('single', $name)->get();
                self::fail('expected InvalidQuery');
            } catch (InvalidQuery $e) {
                self::assertSame(sprintf('manyMatch(): field "%s" is not a many field with rank: best. Fields with it: tag.', $name), $e->getMessage());
            }
        }
    }

    public function testAHiddenOtherFieldChangesNothingForAViewerOfTheField(): void
    {
        $f = $this->install([self::definition(note: true)]);

        $outcomes = [];
        foreach (['empty' => '', 'words' => 'Szeged fo utca Kiss Kossuth', 'long' => str_repeat('szeged utca ', 400)] as $variant => $note) {
            $this->connection->execute('UPDATE ms_customer SET note = :n', ['n' => $note]);
            $f->reindex('scust');
            foreach (['fo utca szeged', 'kiss szeged', 'fo utca budapest', 'szeged', 'fo utca sze*', 'fo utca szgeed', '"fo utca" szeged'] as $text) {
                $explained = $f->in('scust')->query($text)->restrictedToFields('name', 'address')->manyMatch('single')->explainMatches()->highlight('address')->explain();
                $outcomes[$text][$variant] = [
                    array_map(static fn($hit): array => [$hit->id, round($hit->score, 9), $hit->breakdown->bestValues, $hit->highlights], $explained->result->hits),
                    $explained->result->total,
                    array_map(static fn(array $st): array => [$st['label'], $st['sql'], $st['params']], $explained->statements),
                ];
                self::assertStringNotContainsString('t_note', implode(' ', array_column($explained->statements, 'sql')));
            }
        }
        foreach ($outcomes as $text => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'] ?? null, $outcome, sprintf('"%s": the hidden note (%s) changed the outcome', $text, $variant));
            }
        }
        self::assertSame([2], self::ids(self::exact($f->in('scust')->query('fo utca szeged'))->restrictedToFields('name', 'address')->manyMatch('single')->get()));
        self::assertSame([2], self::ids(self::exact($f->in('scust')->query('fo utca szeged'))->restrictedToFields('address')->manyMatch('single')->get()), 'the address alone');
    }

    public function testTheTenantConditionStands(): void
    {
        $this->connection->execute("INSERT INTO ms_customer (id, name, tid) VALUES (6, 'Kovács Zoltán', 2)");
        $this->connection->execute("INSERT INTO ms_address (customer_id, line) VALUES (6, 'Fő utca, Szeged')");
        $f = $this->install([self::definition('tcust', tenant: true)]);

        $one = self::exact($f->in('tcust')->query('fo utca szeged')->forTenant(1))->manyMatch('single')->facets('kind')->get();
        self::assertSame([2], self::ids($one));
        self::assertSame(1, $one->total);
        self::assertSame([['person', 1]], array_map(static fn($v): array => [$v->value, $v->count], $one->facets['kind'] ?? []));
        $two = self::exact($f->in('tcust')->query('fo utca szeged')->forTenant(2))->manyMatch('single')->facets('kind')->exactCounts()->get();
        self::assertSame([6], self::ids($two));
        self::assertSame(1, $two->total);
        self::assertSame([6], self::ids($f->in('tcust')->query('fo utca szgeed')->forTenant(2)->thresholds(['typo' => ['mode' => 'always']])->manyMatch('single')->get()));
        self::assertSame([2], self::ids(self::exact($f->in('tcust')->query('address:szeged address:fo')->forTenant(1))->manyMatch('single')->get()), 'scoped words, the tenant kept');
    }

    public function testFederatedSearchWithTheModeOnOneIndex(): void
    {
        $f = $this->install([self::definition(), IndexDefinition::builder('other')->fromTable('ms_customer')->field('name', 'A')->sync('manual')->build()]);

        $hits = iterator_to_array($f->federated()->index('scust', configure: static fn(SearchBuilder $b): SearchBuilder => $b->manyMatch('single'))->index('other')->query('fo utca szeged')->get(), false);
        self::assertSame([2], array_map(static fn($h): int|string => $h->id, array_values(array_filter($hits, static fn($h): bool => $h->index === 'scust'))));
    }
}
