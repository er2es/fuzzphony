<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * Facets that count the search's own candidates share one statement (one GROUP BY branch each). The reference is the search for
 * that facet alone (a one-branch statement), so each facet of a many-facet search must equal it: values, types, counts, order and limit.
 */
final class SharedFacetsTest extends TestCase
{
    private const array FACETS = ['color', 'size', 'active', 'made', 'maker'];

    private Connection $connection;
    private Fuzzphony $fuzzphony;
    private int $compared = 0;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
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
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->index('facet_docs', false), $this->index('facet_tdocs', true)]));
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

    /**
     * @param list<FacetValue> $values
     *
     * @return list<array{bool|int|string|null, int}>
     */
    private static function plain(array $values): array
    {
        return array_map(static fn(FacetValue $v): array => [$v->value, $v->count], $values);
    }

    /**
     * @param \Closure(SearchBuilder): SearchBuilder $configure
     * @param list<string>                           $names
     */
    private function assertSameAsSingle(\Closure $configure, array $names, string $message, string $index = 'facet_docs'): void
    {
        $all = $configure($this->fuzzphony->in($index))->facets(...$names)->get()->facets;
        self::assertSame($names, array_keys($all), $message . ': the facets come in the order asked');
        foreach ($names as $name) {
            $single = $configure($this->fuzzphony->in($index))->facets($name)->get()->facets;
            self::assertSame(self::plain($single[$name]), self::plain($all[$name]), $message . ': ' . $name);
            $this->compared += $all[$name] === [] ? 0 : 1;
        }
    }

    /** @return array<string, \Closure(SearchBuilder): SearchBuilder> */
    private static function searches(): array
    {
        return [
            'text' => static fn(SearchBuilder $b): SearchBuilder => $b->query('wireless'),
            'two words' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse wireless'),
            'excluded word' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse -wired'),
            'typo, candidates' => static fn(SearchBuilder $b): SearchBuilder => $b->query('wireles mose'),
            'typo always' => static fn(SearchBuilder $b): SearchBuilder => $b->query('hedphones')->thresholds(['fuzzy_mode' => 'always']),
            'fuzzy never' => static fn(SearchBuilder $b): SearchBuilder => $b->query('cable')->thresholds(['fuzzy_mode' => 'never']),
            'relaxed' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse zzzzzz'),
            'field scoped word' => static fn(SearchBuilder $b): SearchBuilder => $b->query('name:keyboard'),
            'restricted viewer' => static fn(SearchBuilder $b): SearchBuilder => $b->query('cable')->restrictedToFields('name'),
            'restricted viewer, typo' => static fn(SearchBuilder $b): SearchBuilder => $b->query('wireles')->restrictedToFields('name', 'description'),
            'browse' => static fn(SearchBuilder $b): SearchBuilder => $b,
            'text + string filter' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->where('maker', 'maker-b'),
            'browse + int range' => static fn(SearchBuilder $b): SearchBuilder => $b->whereBetween('size', 2, 100),
            'browse + null filter' => static fn(SearchBuilder $b): SearchBuilder => $b->whereNull('color'),
            'text + whereIn' => static fn(SearchBuilder $b): SearchBuilder => $b->query('keyboard')->whereIn('size', [2, 9]),
            'candidate limit' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->thresholds(['candidate_limit' => 10, 'fuzzy_mode' => 'never']),
            'candidate limit, browse' => static fn(SearchBuilder $b): SearchBuilder => $b->thresholds(['candidate_limit' => 10]),
            'text, nothing found' => static fn(SearchBuilder $b): SearchBuilder => $b->query('qqqqqqq'),
        ];
    }

    public function testEveryFacetOfASharedStatementEqualsTheFacetAlone(): void
    {
        foreach (self::searches() as $label => $search) {
            foreach ([[], ['exact']] as $exact) {
                foreach ([20, 3, 1] as $values) {
                    $configure = static function (SearchBuilder $b) use ($search, $exact, $values): SearchBuilder {
                        $b = $search($b)->facetValues($values);

                        return $exact === [] ? $b : $b->exactCounts();
                    };
                    $this->assertSameAsSingle($configure, self::FACETS, sprintf('%s, exact %s, %d values', $label, $exact === [] ? 'off' : 'on', $values));
                }
            }
        }
        self::assertGreaterThan(300, $this->compared, 'the matrix compares real facets, not empty ones');
    }

    public function testASubsetAndTheOrderOfTheFacetsAreKept(): void
    {
        foreach ([['size', 'color'], ['active', 'made', 'size'], ['maker', 'maker']] as $names) {
            $this->assertSameAsSingle(static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse'), array_values(array_unique($names)), implode(',', $names));
            $this->assertSameAsSingle(static fn(SearchBuilder $b): SearchBuilder => $b, array_values(array_unique($names)), 'browse ' . implode(',', $names));
        }
    }

    public function testTheCandidateLimitCutsTheSharedFacetsLikeTheOthers(): void
    {
        $search = static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->thresholds(['candidate_limit' => 10, 'fuzzy_mode' => 'never']);
        $all = $search($this->fuzzphony->in('facet_docs'))->facets('color', 'size')->get();
        self::assertTrue($all->totalIsLowerBound);
        self::assertSame(10, array_sum(array_map(static fn(FacetValue $v): int => $v->count, $all->facets['size'])), 'the ten candidates only (a null value is counted too)');
        $exact = $search($this->fuzzphony->in('facet_docs'))->facets('color', 'size')->exactCounts()->get();
        self::assertGreaterThan(10, array_sum(array_map(static fn(FacetValue $v): int => $v->count, $exact->facets['size'])));
    }

    public function testADisjunctiveFacetKeepsItsOwnStatementAndTheOthersShare(): void
    {
        $searches = [
            'text, color chosen' => static fn(SearchBuilder $b): SearchBuilder => $b->query('wireless')->where('color', 'red'),
            'text, two chosen' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->where('color', 'red')->where('active', true),
            'text, a null condition' => static fn(SearchBuilder $b): SearchBuilder => $b->query('mouse')->whereNull('size'),
            'typo, size chosen' => static fn(SearchBuilder $b): SearchBuilder => $b->query('wireles')->whereIn('size', [2, 9]),
            'browse, color chosen' => static fn(SearchBuilder $b): SearchBuilder => $b->where('color', 'blue'),
            'browse, a range on size' => static fn(SearchBuilder $b): SearchBuilder => $b->where('size', '>', 1),
            'restricted viewer, active chosen' => static fn(SearchBuilder $b): SearchBuilder => $b->query('cable')->where('active', false)->restrictedToFields('name', 'description'),
        ];
        foreach ($searches as $label => $search) {
            $this->assertSameAsSingle($search, self::FACETS, $label);
            $this->assertSameAsSingle(static fn(SearchBuilder $b): SearchBuilder => $search($b)->exactCounts()->facetValues(2), self::FACETS, $label . ', exact');
        }
        $explained = $this->fuzzphony->in('facet_docs')->query('wireless')->where('color', 'red')->facets('color', 'size', 'active', 'made')->explain();
        $labels = array_map(static fn(array $s): string => $s['label'], $explained->statements);
        self::assertContains('facet: color', $labels, 'the facet that leaves its own condition out has its own candidates');
        self::assertContains('facet: size, active, made', $labels, 'the others count the search\'s candidates once');
    }

    public function testTheTenantBoundaryHoldsInASharedStatement(): void
    {
        foreach ([null, 'mouse', 'wireles', 'cable'] as $text) {
            $configure = static function (SearchBuilder $b) use ($text): SearchBuilder {
                $b = $b->forTenant(2);

                return $text === null ? $b : $b->query($text);
            };
            $this->assertSameAsSingle($configure, ['color', 'size', 'active', 'made', 'maker'], 'tenant ' . ($text ?? 'browse'), 'facet_tdocs');
            $this->assertSameAsSingle(static fn(SearchBuilder $b): SearchBuilder => $configure($b)->where('color', 'red'), ['color', 'size', 'active'], 'tenant, color chosen ' . ($text ?? 'browse'), 'facet_tdocs');
        }
        $total = 0;
        foreach ($this->fuzzphony->in('facet_tdocs')->forTenant(2)->facets('color', 'size')->get()->facets['size'] as $value) {
            $total += $value->count;
        }
        self::assertSame(45, $total, 'tenant 2 has 45 of the 90 documents, whatever the shared statement counts');
    }

    public function testManyFacetsAreOneStatementAndOneFacetStaysAlone(): void
    {
        $many = $this->fuzzphony->in('facet_docs')->query('mouse')->facets('color', 'size', 'active')->explain();
        self::assertSame(['facet: color, size, active'], array_values(array_filter(array_map(static fn(array $s): string => $s['label'], $many->statements), static fn(string $l): bool => str_starts_with($l, 'facet'))));
        self::assertNotSame([], $many->plan, 'the plan is the search statement, not the facets');

        $one = $this->fuzzphony->in('facet_docs')->query('mouse')->facets('color')->explain();
        self::assertContains('facet: color', array_map(static fn(array $s): string => $s['label'], $one->statements));

        $browse = $this->fuzzphony->in('facet_docs')->facets('color', 'size')->explain();
        self::assertContains('facet: color, size', array_map(static fn(array $s): string => $s['label'], $browse->statements));
    }

    public function testAFacetAskedTwiceIsCountedOnce(): void
    {
        $engine = new PostgresEngine($this->connection);
        $index = $this->index('facet_docs', false);
        $twice = $engine->search($index, new SearchQuery(text: 'mouse', facets: ['color', 'size', 'color']))->facets;
        $once = $engine->search($index, new SearchQuery(text: 'mouse', facets: ['color', 'size']))->facets;

        self::assertSame(['color', 'size'], array_keys($twice));
        self::assertEquals($once, $twice);
        $browse = $engine->search($index, new SearchQuery(facets: ['size', 'size']))->facets;
        self::assertSame(self::plain($engine->search($index, new SearchQuery(facets: ['size']))->facets['size']), self::plain($browse['size']));
    }
}
