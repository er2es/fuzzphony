<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * Facets that count the search's own candidates share one statement (one GROUP BY branch each). The reference is the search for
 * that facet alone (a one-branch statement), so each facet of a many-facet search must equal it: values, types, counts, order and limit.
 */
final class FacetRunnerStatementsTest extends TestCase
{
    private Connection $connection;
    private InterceptingConnection $log;
    private Fuzzphony $fuzzphony;


    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->log = new InterceptingConnection($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fz_facet_doc, fuzzphony_facet_docs, fuzzphony_facet_docs__vocab, fuzzphony_facet_docs__next, fuzzphony_facet_docs__changes, fuzzphony_facet_tdocs, fuzzphony_facet_tdocs__vocab, fuzzphony_facet_tdocs__next, fuzzphony_facet_tdocs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_facet_doc (id bigint PRIMARY KEY, name text NOT NULL, description text NOT NULL, color text, size integer, active boolean, made date, maker text NOT NULL, tenant_id integer NOT NULL)');
        $names = ['wireless mouse', 'wired mouse', 'gaming mouse', 'mouse pad', 'wireless headphones', 'wired headphones', 'mechanical keyboard', 'wireless keyboard', 'usb cable', 'hdmi cable'];
        $colors = ['red', 'green', 'blue', 'black', 'white', 'pink', 'gray', 'gold'];
        $sizes = [9, 10, 100, 2, 2, 1];
        for ($i = 1; $i <= 90; ++$i) {
            $this->connection->execute(
                'INSERT INTO fz_facet_doc VALUES (:id, :name, :description, :color, :size, :active, :made, :maker, :tenant)',
                [
                    'id' => $i,
                    'name' => $names[$i % 10] . ' model ' . $i,
                    'description' => $i % 4 === 0 ? 'with a long cable' : 'plain',
                    'color' => $i % 11 === 0 ? null : $colors[$i % 8],
                    'size' => $i % 13 === 0 ? null : $sizes[$i % 6],
                    'active' => $i % 7 === 0 ? null : $i % 3 !== 0,
                    'made' => $i % 9 === 0 ? null : '2024-01-0' . (1 + $i % 5),
                    // a value that sorts by count, then by name, with a long tail: the limit cuts it
                    'maker' => 'maker-' . chr(97 + (int) floor(sqrt($i)) % 9),
                    'tenant' => 1 + $i % 2,
                ],
            );
        }
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->log), new IndexRegistry([$this->index('facet_docs', false), $this->index('facet_tdocs', true)]));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('facet_docs');
        $this->fuzzphony->reindex('facet_tdocs');
    }

    private function index(string $name, bool $tenant): IndexDefinition
    {
        $builder = IndexDefinition::builder($name)
            ->fromQuery('SELECT id, name, description, color, size, active, made, maker, tenant_id FROM fz_facet_doc')
            ->field('name', 'A', fuzzy: true)
            ->field('description', 'D')
            ->filter('color', 'string')
            ->filter('size', 'int')
            ->filter('active', 'bool')
            ->filter('made', 'date')
            ->filter('maker', 'string')
            ->filter('tenant_id', 'int')
            ->language('english')
            ->sync('manual');
        if ($tenant) {
            $builder->tenant('tenant_id');
        }

        return $builder->build();
    }

    private function resetLog(): void
    {
        $this->log->log = [];
    }

    /**
     * @param \Closure(SearchBuilder): SearchBuilder $search
     *
     * @return list<string> the SQL of the statements a search runs
     */
    private function sqls(\Closure $search): array
    {
        $this->resetLog();
        $search($this->fuzzphony->in('facet_docs'))->get();

        return array_map(static fn(array $entry): string => $entry[0], $this->log->log);
    }

    /**
     * What the table holds: the values of $column among the documents whose name has $word (and the extra condition), counted.
     *
     * @return array<string, int>
     */
    private function truth(string $column, string $where = 'TRUE', string $word = 'mouse'): array
    {
        $counts = [];
        foreach ($this->connection->fetchAll(sprintf("SELECT %s AS v, count(*) AS n FROM fz_facet_doc WHERE name LIKE '%%%s%%' AND %s GROUP BY 1", $column, $word, $where)) as $row) {
            $counts[var_export($column === 'size' && $row['v'] !== null ? Coerce::int($row['v']) : $row['v'], true)] = Coerce::int($row['n']);
        }

        return $counts;
    }

    /**
     * @param list<FacetValue> $values
     *
     * @return array<string, int>
     */
    private static function counts(array $values): array
    {
        $counts = [];
        foreach ($values as $facet) {
            $counts[var_export($facet->value, true)] = $facet->count;
        }

        return $counts;
    }

    public function testFacetsThatKeepEveryConditionCountTheCandidatesOfThePageAndNothingElseIsSet(): void
    {
        $sqls = $this->sqls(static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->facets('color', 'size'));
        $result = ($this->fuzzphony->in('facet_docs'))->query('mouse')->facets('color', 'size')->get();

        self::assertCount(2, $sqls, 'the page, and the facets of its candidates: no candidate selection again, no similarity setting');
        self::assertStringContainsString('WHERE s.id = ANY(CAST(:p0 AS bigint[]))', $sqls[1], 'the ids of the page');
        self::assertEquals(self::truth('color'), self::counts($result->facets['color']));
        self::assertEquals(self::truth('size'), self::counts($result->facets['size']));
        $this->resetLog();
        $this->fuzzphony->in('facet_docs')->query('mouse')->facets('color', 'size')->get();
        $bound = array_values(array_filter($this->log->log[1][1], static fn(mixed $p): bool => is_string($p) && str_starts_with($p, '{')));
        self::assertMatchesRegularExpression('/^\{\d+(,\d+)*\}$/', (string) $bound[0], 'integer ids go in as numbers');
    }

    public function testAChosenFacetIsCountedByThePageStatementItselfAndTheOthersFollow(): void
    {
        $search = static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->where('color', 'red')->thresholds(['typo' => ['mode' => 'never']])->facets('color', 'size');
        $result = $search($this->fuzzphony->in('facet_docs'))->get();

        self::assertEquals(self::truth('color'), self::counts($result->facets['color']), 'what another colour would find');
        self::assertEquals(self::truth('size', "color = 'red'"), self::counts($result->facets['size']), 'under the colour chosen');
        $sqls = $this->sqls($search);
        self::assertStringContainsString('wide_facet', $sqls[0], 'the page statement counts the colour in its own scan');
        $facetStatements = array_values(array_filter($sqls, static fn(string $sql): bool => str_starts_with($sql, 'WITH fc AS')));
        self::assertSame([], array_values(array_filter(array_slice($sqls, 1), static fn(string $sql): bool => str_contains($sql, 'f_color'))), 'no statement of its own for the colour');
        self::assertCount(1, $facetStatements, 'one statement for the other facet, none for the colour');
        self::assertStringContainsString('f_size', $facetStatements[0]);
    }

    public function testAFacetThatLeavesAConditionOutIsCountedByAStatementOfItsOwnWhenThePageHasNoRow(): void
    {
        $search = static fn(SearchBuilder $b): SearchBuilder => $b->where('color', 'nosuchcolor')->thresholds(['typo' => ['mode' => 'never']])->facets('color', 'size');
        $result = $search($this->fuzzphony->in('facet_docs'))->get();

        self::assertSame([], $result->ids());
        self::assertEquals(self::truth('color', 'TRUE', ''), self::counts($result->facets['color']), 'every colour: its own condition is left out');
        self::assertSame([], $result->facets['size']);
    }

    public function testAPageOfOneRowCarriesTheChosenFacetToo(): void
    {
        $result = $this->fuzzphony->in('facet_docs')->query('mouse')->where('color', 'red')->where('size', 2)->thresholds(['typo' => ['mode' => 'never']])->facets('color', 'size')->get();

        self::assertCount(1, $result->hits);
        self::assertEquals(self::truth('color', 'size = 2'), self::counts($result->facets['color']));
        self::assertEquals(self::truth('size', "color = 'red'"), self::counts($result->facets['size']));
    }

    public function testTheFacetsOfATypoSearchCountTheCandidatesOfThePage(): void
    {
        $this->resetLog();
        $result = $this->fuzzphony->in('facet_docs')->query('wireles mose')->facets('color', 'size')->get();
        $sqls = array_map(static fn(array $entry): string => $entry[0], $this->log->log);
        $at = array_keys(array_filter($sqls, static fn(string $sql): bool => str_starts_with($sql, 'WITH fc AS')));
        self::assertCount(1, $at, 'one statement for the facets, on the ids of the page');
        self::assertNotSame([], $result->facets['color']);
    }

    public function testExactCountsOfATypoSearchAreCountedUnderTheSimilarityOfTheSearch(): void
    {
        $exact = $this->fuzzphony->in('facet_docs')->query('wireles')->exactCounts()->facets('color', 'size')->get();
        $page = $this->fuzzphony->in('facet_docs')->query('wireles')->facets('color', 'size')->get();

        self::assertNotSame([], $exact->hits);
        self::assertEquals(self::counts($page->facets['color']), self::counts($exact->facets['color']));
    }
}
