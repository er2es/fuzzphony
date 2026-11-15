<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;

/**
 * A many filter declared `same_row_as: address` (spec 0.12.5 section 1): under manyMatch('single') `where('city', 'Eger')` holds for the
 * SAME address that holds the words. X = {Eger "Fő utca 3"}, Y = {Budapest "Fő utca 1", Eger "Kossuth tér 2"}, Z = {Budapest "Fő utca 5",
 * Eger with no line}, W = name "Fő Kft", {Eger "Petőfi u. 1"}: "fő utca" + Eger finds X, Y and Z by default, only X in the single mode.
 */
final class SameRowFilterTest extends TestCase
{
    private const int X = 1;
    private const int Y = 2;
    private const int Z = 3;
    private const int W = 4;

    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->drop();
        $this->connection->execute("CREATE TABLE sr_customer (id bigint PRIMARY KEY, name text NOT NULL, tid integer NOT NULL DEFAULT 1, kind text NOT NULL DEFAULT 'person')");
        $this->connection->execute('CREATE TABLE sr_address (id serial PRIMARY KEY, customer_id bigint NOT NULL, line text, city text, zip text)');
        $this->connection->execute('CREATE INDEX ON sr_address (customer_id)');
        $this->connection->execute("INSERT INTO sr_customer (id, name, kind) VALUES (1, 'Kiss Anna', 'person'), (2, 'Nagy Béla', 'person'), (3, 'Tóth Ede', 'company'), (4, 'Fő Kft', 'company')");
        $this->addresses(false);
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $tables = [];
        foreach (['srcust', 'srother'] as $index) {
            array_push($tables, 'fuzzphony_' . $index, 'fuzzphony_' . $index . '__next', 'fuzzphony_' . $index . '__changes', 'fuzzphony_' . $index . '__vocab', 'fuzzphony_' . $index . '__values');
        }
        $this->connection->execute('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ', sr_address, sr_customer, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
    }

    /** @param bool $swapped Y's lines change cities (the words in Eger, Kossuth in Budapest): what a viewer without the address must not notice */
    private function addresses(bool $swapped): void
    {
        $this->connection->execute('DELETE FROM sr_address');
        $this->connection->execute(sprintf("INSERT INTO sr_address (customer_id, line, city, zip) VALUES
            (1, 'Eger, Fő utca 3', 'Eger', '3300'),
            (2, 'Budapest, Fő utca 1', '%s', '1051'), (2, 'Eger, Kossuth tér 2', '%s', '3300'),
            (3, 'Budapest, Fő utca 5', 'Budapest', '1052'), (3, NULL, 'Eger', '3301'),
            (4, 'Petőfi u. 1', 'Eger', '3302')", $swapped ? 'Eger' : 'Budapest', $swapped ? 'Budapest' : 'Eger'));
    }

    /**
     * @param string|null $sameRow the field the city filter declares `same_row_as` (null: none)
     */
    private static function definition(?string $sameRow = 'address', string $column = 'city', ?string $granted = null, bool $tenant = false): IndexDefinition
    {
        $builder = IndexDefinition::builder('srcust')->fromTable('sr_customer')
            ->field('name', 'A', fuzzy: true)
            ->field('address', 'C', fuzzy: true, granted: $granted, many: ['from' => 'sr_address', 'key' => 'customer_id', 'column' => 'line', 'rank' => 'best'])
            ->field('post', 'D', many: ['from' => 'sr_address', 'key' => 'customer_id', 'column' => 'zip', 'rank' => 'best'])
            ->filter('city', 'string', many: ['from' => 'sr_address', 'key' => 'customer_id', 'column' => $column] + ($sameRow === null ? [] : ['same_row_as' => $sameRow]))
            ->filter('kind', 'string')
            ->filter('tid', 'int');
        if ($tenant) {
            $builder->tenant('tid');
        }

        return $builder->sync('manual')->build();
    }

    private function install(IndexDefinition $index, bool $reindex = true): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        if ($reindex) {
            $fuzzphony->reindex($index->name);
        }

        return $fuzzphony;
    }

    private static function exact(SearchBuilder $search): SearchBuilder
    {
        return $search->thresholds(['typo' => ['mode' => 'never']]);
    }

    /** @return list<int|string> */
    private static function ids(SearchResult $result): array
    {
        $ids = array_map(static fn($h): int|string => $h->id, $result->hits);
        sort($ids);

        return $ids;
    }

    /**
     * @param (\Closure(SearchBuilder): SearchBuilder)|null $more
     *
     * @return list<int|string>
     */
    private static function find(Fuzzphony $f, string $text, bool $single = true, ?\Closure $more = null): array
    {
        $b = self::exact($f->in('srcust')->query($text))->where('city', 'Eger');
        $b = $single ? $b->manyMatch('single') : $b;

        return self::ids(($more === null ? $b : $more($b))->get());
    }

    private function column(): string
    {
        return (new Names())->sameRowColumnName(self::definition()->filter('city'));
    }

    public function testTheCityHoldsForTheAddressThatHoldsTheWords(): void
    {
        $f = $this->install(self::definition());

        self::assertSame([self::X], self::find($f, 'fő utca'));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca', false), 'the default mode is the document-level filter');
        self::assertSame([self::X, self::W], self::find($f, 'fő'), 'one word: W has it in its name (no address needed)');
        self::assertSame([self::Y], self::find($f, 'kossuth'), 'one word in the Eger address');
        self::assertSame([self::X], self::ids(self::exact($f->in('srcust')->query('fő utca'))->whereIn('city', ['Eger', 'Pécs'])->manyMatch('single')->get()));
        self::assertSame([self::X, self::Y, self::Z], self::ids(self::exact($f->in('srcust')->query('fő utca'))->whereIn('city', ['Eger', 'Budapest'])->manyMatch('single')->get()));
        self::assertSame([self::Y, self::Z], self::ids(self::exact($f->in('srcust')->query('fő utca'))->where('city', 'Budapest')->manyMatch('single')->get()));
        self::assertSame([], self::ids(self::exact($f->in('srcust')->query('fő utca'))->where('city', 'Eger')->where('city', 'Budapest')->manyMatch('single')->get()), 'both on ONE row: none has two cities');
        self::assertSame([self::X], self::find($f, '"fő utca"'), 'a phrase');
        self::assertSame([self::X], self::find($f, 'fő ut', more: static fn(SearchBuilder $b): SearchBuilder => $b->asYouType()), 'as you type');
        self::assertSame([self::X], self::ids($f->in('srcust')->query('fo utcaa')->where('city', 'Eger')->thresholds(['typo' => ['mode' => 'always']])->manyMatch('single')->get()), 'a typo');
        self::assertSame([self::X, self::Y, self::Z], self::ids($f->in('srcust')->query('fo utcaa')->where('city', 'Eger')->thresholds(['typo' => ['mode' => 'always']])->get()));
        self::assertSame([self::X], self::find($f, 'fő utca', more: static fn(SearchBuilder $b): SearchBuilder => $b->exactCounts()));
        self::assertSame(1, self::exact($f->in('srcust')->query('fő utca'))->where('city', 'Eger')->manyMatch('single')->exactCounts()->get()->total);
        self::assertSame([self::X], self::find($f, 'fő utca', more: static fn(SearchBuilder $b): SearchBuilder => $b->manyMatch('single', 'address')), 'the field named');
    }

    public function testNullChecksStayDocumentLevelAndAnUnknownFilterIsUntouched(): void
    {
        $f = $this->install(self::definition());

        self::assertSame([self::X, self::Y, self::Z], self::ids(self::exact($f->in('srcust')->query('fő utca'))->whereNull('city', false)->manyMatch('single')->get()));
        self::assertSame([self::Z], self::ids(self::exact($f->in('srcust')->query('fő utca'))->where('kind', 'company')->manyMatch('single')->get()));
        self::assertSame([], self::ids(self::exact($f->in('srcust')->query('fő utca'))->whereNull('city')->manyMatch('single')->get()));
    }

    public function testWithoutTheSingleModeOrTheOptionTheStatementsAreTheDefaultOnes(): void
    {
        $f = $this->install(self::definition());
        $column = $this->column();

        foreach ([
            'default mode' => $f->in('srcust')->query('fő utca')->where('city', 'Eger'),
            'single, no city condition' => $f->in('srcust')->query('fő utca')->manyMatch('single')->where('kind', 'person'),
            'single, null check' => $f->in('srcust')->query('fő utca')->manyMatch('single')->whereNull('city'),
            'single over another field' => $f->in('srcust')->query('fő utca')->manyMatch('single', 'post')->where('city', 'Eger'),
            'viewer without the address' => $f->in('srcust')->query('fő utca')->manyMatch('single')->where('city', 'Eger')->restrictedToFields('name', 'post'),
        ] as $case => $search) {
            foreach ($search->explain()->statements as $statement) {
                self::assertStringNotContainsString($column, $statement['sql'], $case);
            }
        }
        $statements = static function (Fuzzphony $f, ?\Closure $more = null): array {
            $b = $f->in('srcust')->query('fő utca')->where('city', 'Eger')->facets('city', 'kind');
            $b = $more === null ? $b : $more($b);
            self::assertInstanceOf(SearchBuilder::class, $b);

            return array_map(static fn(array $s): array => [$s['label'], $s['sql'], $s['params']], $b->explain()->statements);
        };
        $without = $this->install(self::definition(null));
        self::assertSame($statements($without), $statements($f), 'the default mode reads the same with or without the option');
        $restricted = static fn(SearchBuilder $b): SearchBuilder => $b->manyMatch('single')->restrictedToFields('name');
        self::assertSame($statements($without, $restricted), $statements($f, $restricted), 'a viewer without the address: the statement of a definition without the option');
    }

    public function testTheCoupledStatementsReadTheColumnAndDropTheShortcuts(): void
    {
        $f = $this->install(self::definition());
        $sql = implode("\n", array_map(static fn(array $s): string => $s['sql'], $f->in('srcust')->query('kossuth')->where('city', 'Eger')->manyMatch('single')->explain()->statements));

        self::assertStringContainsString(sprintf('coalesce(v."%s" && ARRAY[', $this->column()), $sql, 'one word: the value rows are read');
        self::assertStringNotContainsString('s."n_address" <= 1', $sql, 'no one-value shortcut');
        $plain = implode("\n", array_map(static fn(array $s): string => $s['sql'], $f->in('srcust')->query('fő utca')->where('kind', 'person')->manyMatch('single')->explain()->statements));
        self::assertStringContainsString('s."n_address" <= 1', $plain, 'without a coupled condition the shortcut stays');
        $in = $f->in('srcust')->query('fő utca')->whereIn('city', ['Eger', 'Pécs'])->manyMatch('single')->explain()->statements[0];
        self::assertSame(1, preg_match(sprintf('/coalesce\(v\."%s" && ARRAY\[(:p\d+), (:p\d+)\]::text\[\], true\)/', $this->column()), $in['sql'], $m));
        self::assertSame(['Eger', 'Pécs'], [$in['params'][ltrim($m[1], ':')] ?? $in['params'][$m[1]] ?? null, $in['params'][ltrim($m[2], ':')] ?? $in['params'][$m[2]] ?? null], 'the IN values, bound');
    }

    public function testExplainMatchesAndTheHighlightShowTheFilteredAddress(): void
    {
        $this->connection->execute("INSERT INTO sr_customer (id, name) VALUES (6, 'Vass Vera')");
        $this->connection->execute("INSERT INTO sr_address (customer_id, line, city) VALUES (6, 'Fő utca 2, Fő utca sarok, Budapest', 'Budapest'), (6, 'Fő utca 8, Eger', 'Eger')");
        $f = $this->install(self::definition());
        $search = static fn(bool $city): SearchResult => ($city ? self::exact($f->in('srcust')->query('fő utca'))->where('city', 'Eger') : self::exact($f->in('srcust')->query('fő utca')))
            ->manyMatch('single')->explainMatches()->highlight('address')->limit(10)->get();

        $hit = array_values(array_filter($search(true)->hits, static fn($h): bool => $h->id === 6))[0] ?? null;
        self::assertNotNull($hit);
        self::assertSame(['address' => 1], $hit->breakdown->bestValues, 'ord 1: "Fő utca 8, Eger" (ordered by the value)');
        self::assertStringContainsString('Eger', $hit->highlights['address'] ?? '');
        $free = array_values(array_filter($search(false)->hits, static fn($h): bool => $h->id === 6))[0] ?? null;
        self::assertNotNull($free);
        self::assertSame(['address' => 0], $free->breakdown->bestValues, 'without the city the best value is the other one');
        self::assertStringContainsString('Budapest', $free->highlights['address'] ?? '');
    }

    public function testTheFacetOfTheCoupledFilterCountsWhatChoosingItShows(): void
    {
        $f = $this->install(self::definition());
        foreach (['fő utca', 'kossuth', 'fő', 'utca'] as $text) {
            foreach ([null, 'Eger', 'Budapest'] as $chosen) {
                foreach ([false, true] as $exactCounts) {
                    $b = self::exact($f->in('srcust')->query($text))->manyMatch('single')->facets('city', 'kind');
                    $b = $chosen === null ? $b : $b->where('city', $chosen);
                    $b = $exactCounts ? $b->exactCounts() : $b;
                    $result = $b->get();
                    $counts = [];
                    foreach ($result->facets['city'] as $value) {
                        $counts[(string) $value->value] = $value->count;
                    }
                    foreach ($counts as $value => $count) {
                        if ($value === '') {
                            continue;
                        }
                        $shown = self::exact($f->in('srcust')->query($text))->manyMatch('single')->where('city', $value)->exactCounts()->get()->total;
                        self::assertSame($shown, $count, sprintf('"%s" (%s): the facet value %s', $text, $chosen ?? 'no city', $value));
                    }
                    $kinds = array_sum(array_map(static fn($v): int => $v->count, $result->facets['kind']));
                    self::assertSame($result->total, $kinds, 'the other facets count the coupled result');
                }
            }
        }
        $counts = static fn(SearchResult $r): array => array_map(static fn($v): array => [$v->value, $v->count], $r->facets['city']);
        self::assertSame([['Budapest', 2], ['Eger', 1]], $counts(self::exact($f->in('srcust')->query('fő utca'))->manyMatch('single')->facets('city')->get()));
        self::assertSame([['Eger', 3], ['Budapest', 2]], $counts(self::exact($f->in('srcust')->query('fő utca'))->facets('city')->get()), 'the default mode counts per document');
        self::assertSame([['Eger', 1]], $counts(self::exact($f->in('srcust')->query('kossuth'))->manyMatch('single')->facets('city')->get()), 'Y\'s Kossuth address is in Eger');
    }

    public function testRowsWrittenBeforeTheOptionBehaveAsTodayUntilTheReindex(): void
    {
        $this->install(self::definition(null));
        $f = $this->install(self::definition(), reindex: false);
        $column = $this->column();

        self::assertSame(5, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM fuzzphony_srcust__values WHERE field = \'address\' AND "%s" IS NULL', $column))));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca'), 'unknown rows: the document-level filter');
        $warning = self::check($f, 'Same row "city"');
        self::assertNotNull($warning);
        self::assertSame('warning', $warning->status->value);
        self::assertStringContainsString('5 value rows of "address" do not know their "city" yet (indexed before same_row_as): they match the city of any address until fuzzphony:reindex srcust', $warning->message);

        // a partial sync rewrites Y alone: a mixed table, each document by its own rows
        $f->refresh('srcust', [self::Y]);
        self::assertSame([self::X, self::Z], self::find($f, 'fő utca'));
        self::assertSame(3, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM fuzzphony_srcust__values WHERE field = \'address\' AND "%s" IS NULL', $column))));

        $f->reindex('srcust');
        self::assertSame([self::X], self::find($f, 'fő utca'));
        self::assertSame('ok', self::check($f, 'Same row "city"')?->status->value);
        self::assertSame(['{Eger}', '{Budapest}', '{Eger}', '{Budapest}', '{Eger}'], array_map(static fn(array $r): string => Coerce::str($r['c']), $this->connection->fetchAll(sprintf('SELECT "%s"::text AS c FROM fuzzphony_srcust__values WHERE field = \'address\' ORDER BY doc, ord', $column))));
        self::assertSame(6, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM fuzzphony_srcust__values WHERE field = \'post\' AND "%s" IS NULL', $column))), 'another field\'s rows: NULL');
    }

    public function testAChildRowWithoutACityIsStoredAsNoCityNotUnknown(): void
    {
        $this->connection->execute('UPDATE sr_address SET city = NULL WHERE customer_id = 1');
        $f = $this->install(self::definition());

        self::assertSame('{}', Coerce::str($this->connection->fetchValue(sprintf('SELECT "%s"::text FROM fuzzphony_srcust__values WHERE doc = 1 AND field = \'address\'', $this->column()))));
        self::assertSame([], self::find($f, 'fő utca'), 'X has no city: neither its row nor the document is in Eger');
    }

    public function testADowngradedRefreshWritesUnknownForItsDocumentOnly(): void
    {
        $f = $this->install(self::definition());
        // what the 0.12.4 refresh function writes: its INSERT lists (doc, field, ord, v, t, z), the new column gets NULL
        $this->connection->execute(sprintf('UPDATE fuzzphony_srcust__values SET "%s" = NULL WHERE doc = 2', $this->column()));

        self::assertSame([self::X, self::Y], self::find($f, 'fő utca'));
    }

    public function testTheTransitionsNeverTrustAValueOfAnotherDefinition(): void
    {
        $column = $this->column();
        $exists = fn(string $name): bool => $this->connection->fetchValue("SELECT count(*) FROM pg_attribute WHERE attrelid = to_regclass('fuzzphony_srcust__values') AND attname = :c AND NOT attisdropped", ['c' => $name]) === 1;
        $f = $this->install(self::definition());
        self::assertSame([self::X], self::find($f, 'fő utca'));

        // the option removed: the column goes, today's behaviour
        $f = $this->install(self::definition(null), reindex: false);
        self::assertFalse($exists($column));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca'));
        self::assertNull(self::check($f, 'Same row "city"'));

        // back on without a reindex: a new, empty column (NULL), never the values of the old one
        $f = $this->install(self::definition(), reindex: false);
        self::assertTrue($exists($column));
        self::assertSame(0, Coerce::int($this->connection->fetchValue(sprintf('SELECT count(*) FROM fuzzphony_srcust__values WHERE "%s" IS NOT NULL', $column))));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca'));

        // the option moved to another field (post): the address rows are no longer coupled
        $f->reindex('srcust');
        self::assertSame([self::X], self::find($f, 'fő utca'));
        $post = (new Names())->sameRowColumnName(self::definition('post')->filter('city'));
        $f = $this->install(self::definition('post'), reindex: false);
        self::assertTrue($exists($post));
        self::assertFalse($exists($column));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca'));

        // the filter column changed (city -> zip): another hashed name, NULL until the reindex
        $zip = (new Names())->sameRowColumnName(self::definition(column: 'zip')->filter('city'));
        self::assertNotSame($column, $zip);
        $f = $this->install(self::definition(column: 'zip'), reindex: false);
        self::assertFalse($exists($post));
        self::assertTrue($exists($zip));
        self::assertSame([self::X, self::Y, self::Z], self::find($f, 'fő utca'), 'the document-level array still holds the cities until the reindex');
        $f->reindex('srcust');
        self::assertSame([self::X], self::ids(self::exact($f->in('srcust')->query('fő utca'))->where('city', '3300')->manyMatch('single')->get()));
    }

    /**
     * The transition matrix: every definition change applied WITHOUT a reindex (and after one), for a viewer who may see the address and one
     * who may not. The restricted viewer's outcome (hits, scores, totals, facets) is the same in every state and for every pairing of Y's
     * lines and cities (which only the address shows), and its statements never read the values table.
     */
    public function testTheTransitionMatrixKeepsAViewerWithoutTheAddressIndependent(): void
    {
        $states = [
            'no option, indexed' => [self::definition(null), true, [self::X, self::Y, self::Z]],
            'option added, apply only' => [self::definition(), false, [self::X, self::Y, self::Z]],
            'option, reindexed' => [self::definition(), true, [self::X]],
            'address granted, apply only' => [self::definition(granted: 'ROLE_ADDRESS'), false, [self::X]],
            'moved to post, apply only' => [self::definition('post', granted: 'ROLE_ADDRESS'), false, [self::X, self::Y, self::Z]],
            'back to address, apply only' => [self::definition(granted: 'ROLE_ADDRESS'), false, [self::X, self::Y, self::Z]],
            'filter column zip, apply only' => [self::definition(column: 'zip'), false, [self::X, self::Y, self::Z]],
            'back to city, reindexed' => [self::definition(), true, [self::X]],
            'option removed, apply only' => [self::definition(null), false, [self::X, self::Y, self::Z]],
        ];
        $restricted = [];
        $pairings = [];
        foreach ([false, true] as $swapped) {
            $this->drop();
            $this->setUp();
            $this->addresses($swapped);
            foreach ($states as $state => [$definition, $reindex, $expected]) {
                $f = $this->install($definition, $reindex);
                if (!$swapped) {
                    self::assertSame($expected, self::find($f, 'fő utca'), $state . ': the viewer who sees the address');
                }
                // 'name': no many field at all (the statement never reads the values table); 'name+post': another many field it may see
                foreach (['name' => ['name'], 'name+post' => ['name', 'post']] as $viewer => $allowed) {
                    foreach (['fő utca', 'kossuth', 'fő', '"fő utca"', 'kiss', '3300'] as $text) {
                        foreach ([['city', 'Eger'], ['city', 'Budapest'], ['kind', 'person']] as [$filter, $value]) {
                            foreach (['never', 'always'] as $mode) {
                                $explained = $f->in('srcust')->query($text)->where($filter, $value)->manyMatch('single')->restrictedToFields(...$allowed)
                                    ->thresholds(['typo' => ['mode' => $mode]])->facets('city', 'kind')->explain();
                                $statements = [];
                                foreach ($explained->statements as $statement) {
                                    self::assertStringNotContainsString($this->column(), $statement['sql'], $state);
                                    if ($viewer === 'name') {
                                        self::assertStringNotContainsString('__values', $statement['sql'], $state);
                                    }
                                    $statements[] = [$statement['sql'], $statement['params']];
                                }
                                $r = $explained->result;
                                $outcome = [
                                    array_map(static fn($h): array => [$h->id, round($h->score, 9)], $r->hits),
                                    $r->total,
                                    array_map(static fn($facet): array => array_map(static fn($v): array => [$v->value, $v->count], $facet), $r->facets),
                                ];
                                $case = $viewer . ' | ' . $text . ' | ' . $filter . '=' . $value . ' | ' . $mode;
                                // across the states (the restricted outcome never depends on the definition's same_row_as) ...
                                $restricted[$case][$state . ($swapped ? ' (swapped)' : '')] = $outcome;
                                // ... and across the pairings within a state, the statements too
                                $pairings[$case . ' | ' . $state][$swapped ? 'swapped' : 'plain'] = [$outcome, $statements];
                            }
                        }
                    }
                }
            }
        }
        foreach ($pairings as $case => $byPairing) {
            self::assertSame($byPairing['plain'] ?? null, $byPairing['swapped'], sprintf('%s: which address holds which city changed what a viewer without the address finds', $case));
        }
        foreach ($restricted as $case => $byState) {
            $first = reset($byState);
            foreach ($byState as $state => $outcome) {
                self::assertSame($first, $outcome, sprintf('%s: %s changed what a viewer without the address finds', $case, $state));
            }
        }
    }

    public function testNoTenantCrossing(): void
    {
        $this->connection->execute("INSERT INTO sr_customer (id, name, tid) VALUES (5, 'Papp Pál', 2)");
        $this->connection->execute("INSERT INTO sr_address (customer_id, line, city) VALUES (5, 'Fő utca 9, Eger', 'Eger')");
        $f = $this->install(self::definition(tenant: true));

        self::assertSame([self::X], self::ids(self::exact($f->in('srcust')->query('fő utca'))->forTenant(1)->where('city', 'Eger')->manyMatch('single')->get()));
        self::assertSame([5], self::ids(self::exact($f->in('srcust')->query('fő utca'))->forTenant(2)->where('city', 'Eger')->manyMatch('single')->get()));
        $counts = array_map(static fn($v): array => [$v->value, $v->count], self::exact($f->in('srcust')->query('fő utca'))->forTenant(2)->manyMatch('single')->facets('city')->get()->facets['city']);
        self::assertSame([['Eger', 1]], $counts);
    }

    public function testASearchDuringARefreshSeesOneSnapshot(): void
    {
        $f = $this->install(self::definition());
        $other = PostgresTestCase::connect();
        $other->execute('BEGIN');
        try {
            // Y's words move to its Eger address in a refresh that has not committed yet
            $other->execute("UPDATE sr_address SET line = 'Eger, Fő utca 2' WHERE customer_id = 2 AND city = 'Eger'");
            $other->execute('SELECT fuzzphony_refresh_srcust(ARRAY[2]::bigint[])');
            self::assertSame([self::X], self::find($f, 'fő utca'), 'the committed rows only');
        } finally {
            $other->execute('COMMIT');
        }
        self::assertSame([self::X, self::Y], self::find($f, 'fő utca'));
    }

    public function testFederatedSearchesCoupleEachIndexOnItsOwn(): void
    {
        $f = $this->install(self::definition());
        $hits = iterator_to_array($f->federated()->index('srcust', configure: static fn(SearchBuilder $b): SearchBuilder => $b->manyMatch('single')->where('city', 'Eger'))->query('fő utca')->get(), false);

        self::assertSame([self::X], array_map(static fn($h): int|string => $h->id, $hits));
    }

    public function testTheDoctorReportsAMissingColumnAndAStaleOne(): void
    {
        $f = $this->install(self::definition());
        $column = $this->column();
        $this->connection->execute(sprintf('ALTER TABLE fuzzphony_srcust__values DROP COLUMN "%s"', $column));
        $this->connection->execute('ALTER TABLE fuzzphony_srcust__values ADD COLUMN fr_old_00000000 text[]');

        $missing = self::check($f, 'Same row "city"');
        self::assertNotNull($missing);
        self::assertSame('error', $missing->status->value);
        self::assertSame('bin/console fuzzphony:schema --apply', $missing->fix);
        $stale = self::check($f, 'Same row');
        self::assertNotNull($stale);
        self::assertSame('warning', $stale->status->value);
        self::assertStringContainsString('fr_old_00000000', $stale->message);

        $this->connection->execute('ALTER TABLE fuzzphony_srcust__values DROP COLUMN fr_old_00000000');
        $f = $this->install(self::definition());
        self::assertSame('ok', self::check($f, 'Same row "city"')?->status->value);
        self::assertNull(self::check($f, 'Same row'));
    }

    private static function check(Fuzzphony $f, string $name): ?Check
    {
        foreach ($f->inspect('srcust')->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        return null;
    }
}
