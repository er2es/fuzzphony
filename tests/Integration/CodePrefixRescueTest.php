<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A search that found nothing is searched once more with its last word as the beginning of a code (CodePrefix):
 * `ORD-2025-00003` finds `ORD-2025-000030`, although `00003` is no whole word of the code.
 */
final class CodePrefixRescueTest extends TestCase
{
    private const array INDEXES = ['cpr_orders', 'cpr_tenant', 'cpr_customers', 'cpr_people', 'cpr_site'];

    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_cpr_order, fz_cpr_customer, fz_cpr_person CASCADE');
        $tables = [];
        foreach (self::INDEXES as $index) {
            array_push($tables, 'fuzzphony_' . $index, 'fuzzphony_' . $index . '__next', 'fuzzphony_' . $index . '__changes', 'fuzzphony_' . $index . '__vocab');
        }
        $this->connection->execute('DROP TABLE IF EXISTS ' . implode(', ', $tables) . ' CASCADE');
        $this->connection->execute('CREATE TABLE fz_cpr_person (id bigint PRIMARY KEY, name text NOT NULL, note text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_cpr_person VALUES (1, 'Anna Kovacs', 'flat 00003'), (2, 'Bela Nagy', 'door 00003 left'), (3, 'Cecil Toth', 'nothing')");
        $this->connection->execute("CREATE TABLE fz_cpr_order (id bigint PRIMARY KEY, number text NOT NULL, customer text NOT NULL, ref text NOT NULL DEFAULT '', tenant_id int NOT NULL DEFAULT 1)");
        $this->connection->execute('CREATE TABLE fz_cpr_customer (id bigint PRIMARY KEY, name text NOT NULL)');
        $rows = [
            [1, 'ORD-2025-000030', 'Kovacs Kft', 'REF-77001', 1],
            [2, 'ORD-2025-000031', 'Nagy Bt', '', 1],
            [3, 'ORD-2025-000300', 'Kovacs Kft', '', 1],
            [4, 'ORD-2026-000032', 'Szabo Zrt', '', 1],
            [5, 'ORD-2025-000035', 'Toth Kft', '', 2],
        ];
        foreach ($rows as [$id, $number, $customer, $ref, $tenant]) {
            $this->connection->execute('INSERT INTO fz_cpr_order (id, number, customer, ref, tenant_id) VALUES (:id, :n, :c, :r, :t)', ['id' => $id, 'n' => $number, 'c' => $customer, 'r' => $ref, 't' => $tenant]);
        }
        $this->connection->execute("INSERT INTO fz_cpr_customer VALUES (1, 'Kovacs Kft'), (2, 'Ordway Ltd')");
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('cpr_orders')->fromQuery('SELECT id, number, customer, ref FROM fz_cpr_order')->watch('fz_cpr_order')
                ->field('number', 'A', code: true)->field('customer', 'B', fuzzy: true)->field('ref', 'C', code: true)->language('simple')->sync('manual')->build(),
            IndexDefinition::builder('cpr_tenant')->fromQuery('SELECT id, number, customer, tenant_id FROM fz_cpr_order')->watch('fz_cpr_order')
                ->field('number', 'A', code: true)->field('customer', 'B')->filter('tenant_id', 'int')->tenant('tenant_id')->language('simple')->sync('manual')->build(),
            IndexDefinition::builder('cpr_customers')->fromQuery('SELECT id, name FROM fz_cpr_customer')->watch('fz_cpr_customer')
                ->field('name', 'A', fuzzy: true)->language('simple')->sync('manual')->build(),
            IndexDefinition::builder('cpr_people')->fromQuery('SELECT id, name, note FROM fz_cpr_person')->watch('fz_cpr_person')
                ->field('name', 'A', fuzzy: true)->field('note', 'C')->language('simple')->sync('manual')->build(),
            IndexDefinition::builder('cpr_site')
                ->source('person', 'SELECT id, name AS title, note AS body FROM fz_cpr_person', watch: [new Watch('fz_cpr_person')])
                ->source('customer', "SELECT id, name AS title, '' AS body FROM fz_cpr_customer", watch: [new Watch('fz_cpr_customer')])
                ->field('title', 'A', fuzzy: true)->field('body', 'C')->language('simple')->sync('manual')->build(),
        ]));
        $this->fuzzphony->schema()->apply($this->connection);
        foreach (self::INDEXES as $index) {
            $this->fuzzphony->reindex($index);
        }
    }

    public function testAPartialLastPartOfACodeIsRescuedAsABeginning(): void
    {
        $result = $this->fuzzphony->in('cpr_orders')->query('ORD-2025-00003')->get();

        self::assertEqualsCanonicalizing([1, 2, 5], $result->ids());
        self::assertSame(3, $result->total);
        self::assertSame('ORD-2025-00003', $result->correctedFrom);
        self::assertSame('number:ORD-2025-00003* | ref:ORD-2025-00003*', $result->correctedTo);
        self::assertContains("No results for 'ORD-2025-00003'; showing results for 'number:ORD-2025-00003* | ref:ORD-2025-00003*'.", $result->warnings);
    }

    public function testTheCodeWithoutSeparatorsAndAfterOtherWords(): void
    {
        self::assertEqualsCanonicalizing([1, 2, 5], $this->fuzzphony->in('cpr_orders')->query('ORD202500003')->get()->ids());
        // with other words, the relaxation answers first ("kovacs" alone); without it, the rescue does
        self::assertNull($this->fuzzphony->in('cpr_orders')->query('kovacs ORD-2025-00003')->get()->correctedFrom);
        $result = $this->fuzzphony->in('cpr_orders')->query('kovacs ORD-2025-00003')->thresholds(['rescue' => ['drop_unmatched' => false]])->get();
        self::assertSame([1], $result->ids());
        self::assertSame('kovacs (number:ORD-2025-00003* | ref:ORD-2025-00003*)', $result->correctedTo);
        self::assertSame([2], $this->fuzzphony->in('cpr_orders')->query('number:ORD-2025-000031')->get()->ids(), 'a whole code is no rescue');
    }

    public function testASearchWithResultsRunsExactlyAsBefore(): void
    {
        $found = $this->fuzzphony->in('cpr_orders')->query('ORD-2025')->facets()->explain();

        self::assertNull($found->result->correctedFrom);
        self::assertSame(4, $found->result->total);
        self::assertSame([], array_values(array_filter(array_column($found->statements, 'label'), static fn(string $l): bool => str_starts_with($l, 'rescue: '))));
    }

    public function testARescueThatFindsNothingReturnsTheOriginalEmptyResult(): void
    {
        $result = $this->fuzzphony->in('cpr_orders')->query('ORD-2025-9')->get();

        self::assertSame([], $result->ids());
        self::assertNull($result->correctedFrom);
        self::assertSame([], $result->warnings);
        $labels = array_column($this->fuzzphony->in('cpr_orders')->query('ORD-2025-9')->explain()->statements, 'label');
        self::assertContains('rescue: full-text', $labels, 'explain() lists what the attempt ran');
    }

    public function testSwitchedOffWithCorrectWhenEmptyAndNeverForSearchAsYouType(): void
    {
        $off = $this->fuzzphony->in('cpr_orders')->query('ORD-2025-00003')->thresholds(['rescue' => ['correct_when_empty' => false]])->get();
        self::assertSame([], $off->ids());
        self::assertNull($off->correctedFrom);

        // search-as-you-type leaves a word after a hyphen as typed, so the rescue still makes it a prefix; "ORD 2025 00003" is one already
        $typing = $this->fuzzphony->in('cpr_orders')->query('ORD-2025-00003')->asYouType()->get();
        self::assertEqualsCanonicalizing([1, 2, 5], $typing->ids());
        self::assertSame('ORD-2025-00003', $typing->correctedFrom);
        $spaced = $this->fuzzphony->in('cpr_orders')->query('ORD 2025 00003')->asYouType()->get();
        self::assertEqualsCanonicalizing([1, 2, 5], $spaced->ids());
        self::assertNull($spaced->correctedFrom);
    }

    public function testARestrictedViewerIsRescuedOnlyByTheCodeFieldsItMaySearch(): void
    {
        $search = $this->fuzzphony->in('cpr_orders');
        // the hidden code field "ref" has REF-77001: its beginning finds nothing for a viewer without it, with no trace
        $before = $search->restrictedToFields('number', 'customer')->query('REF-770')->get();
        self::assertSame([], $before->ids());
        self::assertNull($before->correctedFrom);
        self::assertSame([], $before->warnings);
        $this->connection->execute("UPDATE fz_cpr_order SET ref = 'ORD-2025-000039' WHERE id = 4");
        $this->fuzzphony->reindex('cpr_orders');
        foreach (['REF-770', 'ORD-2025-00003', 'kovacs ORD-2025-00003'] as $text) {
            $restricted = $search->restrictedToFields('number', 'customer')->query($text)->get();
            $without = $search->restrictedToFields('customer')->query($text)->get();
            self::assertNotContains(4, $restricted->ids(), $text . ': the hidden ref never matches');
            self::assertNotContains(4, $without->ids(), $text);
            self::assertNull($without->correctedFrom, $text . ': no code field to rescue with');
        }
        self::assertSame('number:ORD-2025-00003*', $search->restrictedToFields('number', 'customer')->query('ORD-2025-00003')->get()->correctedTo);
        self::assertContains(4, $search->query('ORD-2025-00003')->get()->ids(), 'an unrestricted viewer finds it in ref');
    }

    public function testTheTenantFilterStays(): void
    {
        self::assertEqualsCanonicalizing([1, 2], $this->fuzzphony->in('cpr_tenant')->query('ORD-2025-00003')->forTenant(1)->get()->ids());
        self::assertSame([5], $this->fuzzphony->in('cpr_tenant')->query('ORD-2025-00003')->forTenant(2)->get()->ids());
    }

    public function testAFederatedSearchRescuesTheIndexThatHasTheCode(): void
    {
        $result = $this->fuzzphony->federated()->index('cpr_orders')->index('cpr_customers')->query('ORD-2025-00003')->get();

        self::assertEqualsCanonicalizing([1, 2, 5], $result->results['cpr_orders']->ids());
        self::assertSame('ORD-2025-00003', $result->results['cpr_orders']->correctedFrom);
        self::assertNull($result->results['cpr_customers']->correctedFrom);
    }

    public function testAFederatedSearchRescuesOnlyTheIndexThatFoundNothingAndRanksItsHitsAfterTheGenuineOnes(): void
    {
        // "00003" is a word of two people's notes; in the orders it is only the beginning of a part of a code
        $result = $this->federated(['cpr_people' => [1.0, 2], 'cpr_customers' => [1.2, 2], 'cpr_orders' => [0.8, 1]])->limit(10)->get();

        self::assertNull($result->results['cpr_people']->correctedFrom, 'the index with hits is not rescued');
        self::assertSame('00003', $result->results['cpr_orders']->correctedFrom);
        self::assertNull($result->results['cpr_customers']->correctedFrom);
        self::assertSame(['cpr_people:1', 'cpr_people:2', 'cpr_orders:1', 'cpr_orders:2', 'cpr_orders:4', 'cpr_orders:5'], self::keys($result->hits));
        self::assertSame(6, $result->total);
        self::assertFalse($result->totalIsLowerBound);
        self::assertSame(["[cpr_orders] No results for '00003'; showing results for 'number:00003* | ref:00003*'."], $result->warnings);
        foreach ($result->hits as $hit) {
            self::assertNotSame([], $hit->matches ?? [], 'every hit says why it was found');
            if ($hit->index === 'cpr_orders') {
                self::assertSame('number', ($hit->matches ?? [])[0]->field ?? null);
            }
        }

        // a heavier rescued index does not outrank a genuine hit either
        $heavy = $this->federated(['cpr_people' => [1.0, 0], 'cpr_orders' => [5.0, 0]])->limit(10)->get();
        self::assertSame(['cpr_people:1', 'cpr_people:2', 'cpr_orders:1', 'cpr_orders:2', 'cpr_orders:4', 'cpr_orders:5'], self::keys($heavy->hits));
    }

    public function testFloorsBringRescuedHitsOntoThePageAndEveryHitIsShownOnce(): void
    {
        $first = $this->federated(['cpr_people' => [1.0, 1], 'cpr_orders' => [0.8, 2]])->limit(3)->get();
        $second = $this->federated(['cpr_people' => [1.0, 1], 'cpr_orders' => [0.8, 2]])->limit(3, 3)->get();

        self::assertSame(['cpr_people', 'cpr_orders', 'cpr_orders'], array_map(static fn(Hit $h): ?string => $h->index, $first->hits), 'the floor of 2 holds, genuine first');
        $shown = [...self::keys($first->hits), ...self::keys($second->hits)];
        self::assertEqualsCanonicalizing(['cpr_people:1', 'cpr_people:2', 'cpr_orders:1', 'cpr_orders:2', 'cpr_orders:4', 'cpr_orders:5'], $shown);
    }

    public function testAFederatedSearchWhereNoIndexHadAResult(): void
    {
        $result = $this->federated(['cpr_people' => [1.0, 2], 'cpr_customers' => [1.2, 2], 'cpr_orders' => [0.8, 1]], 'ORD-2025-00003')->limit(10)->get();

        self::assertSame(['cpr_orders:1', 'cpr_orders:2', 'cpr_orders:5'], self::keys($result->hits));
        self::assertSame(3, $result->total);
        self::assertNull($result->results['cpr_people']->correctedFrom);
        // the demo's way: search-as-you-type in every index
        $typing = $this->fuzzphony->federated()->index('cpr_people', 1.0, static fn(SearchBuilder $b): SearchBuilder => $b->asYouType())
            ->index('cpr_orders', 0.8, static fn(SearchBuilder $b): SearchBuilder => $b->asYouType(), 1)->query('ORD-2025-00003')->get();
        self::assertSame(['cpr_orders:1', 'cpr_orders:2', 'cpr_orders:5'], self::keys($typing->hits));
    }

    public function testAMultiSourceIndexWithoutACodeFieldIsUnchanged(): void
    {
        $site = $this->fuzzphony->in('cpr_site')->query('00003')->get();
        self::assertSame(2, $site->total);
        self::assertNull($site->correctedFrom);

        $none = $this->fuzzphony->in('cpr_site')->query('ORD-2025-00003')->get();
        self::assertSame([], $none->ids());
        self::assertNull($none->correctedFrom);
        self::assertSame([], $none->warnings);

        $federated = $this->federated(['cpr_site' => [1.0, 0], 'cpr_orders' => [1.0, 0]], 'ORD-2025-00003')->get();
        self::assertNull($federated->results['cpr_site']->correctedFrom);
        self::assertSame(['cpr_orders:1', 'cpr_orders:2', 'cpr_orders:5'], self::keys($federated->hits));
    }

    /** @param array<string, array{float, int}> $indexes name => [weight, floor] */
    private function federated(array $indexes, string $text = '00003'): FederatedSearch
    {
        $search = $this->fuzzphony->federated()->query($text);
        foreach ($indexes as $name => [$weight, $floor]) {
            $search = $search->index($name, $weight, static fn(SearchBuilder $b): SearchBuilder => $b->explainMatches(), $floor);
        }

        return $search;
    }

    /**
     * index:id in page order, the ids sorted inside each run of one index (their order there is the index's own ranking)
     *
     * @param list<Hit> $hits
     *
     * @return list<string>
     */
    private static function keys(array $hits): array
    {
        $runs = [];
        $last = null;
        foreach ($hits as $hit) {
            if ($hit->index !== $last) {
                $runs[] = [];
                $last = $hit->index;
            }
            $runs[array_key_last($runs)][] = $hit->index . ':' . $hit->id;
        }
        $runs = array_map(static function (array $run): array {
            sort($run);

            return $run;
        }, $runs);

        return array_merge(...$runs);
    }
}
