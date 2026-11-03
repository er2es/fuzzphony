<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** SearchBuilder::with(): the returnable columns of the source row, read by the ids of the page. */
final class ReturnedFieldsTest extends TestCase
{
    private const string ARTICLE = '0b1d6f0e-0000-4000-8000-000000000001';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->drop();
        $this->connection->execute('CREATE TABLE rf_item (id bigint PRIMARY KEY, title text NOT NULL, slug text, secret text, price numeric(10,2), qty integer, ratio double precision, live boolean, released date, created timestamptz, tags text[], meta jsonb, tid integer NOT NULL DEFAULT 1)');
        $this->connection->execute("INSERT INTO rf_item VALUES (1, 'Wireless mouse', 'wireless-mouse', 'margin zebrafish', 19.90, 5, 0.5, true, '2026-01-02', '2026-01-02 03:04:05+00', '{a,b}', '{\"k\": [1, 2]}', 1), (2, 'Wired keyboard', 'wired-keyboard', 'plain', NULL, NULL, NULL, false, NULL, NULL, NULL, NULL, 2)");
        $this->connection->execute('CREATE TABLE rf_article (id uuid PRIMARY KEY, headline text NOT NULL, url text)');
        $this->connection->execute("INSERT INTO rf_article VALUES ('" . self::ARTICLE . "', 'Choosing a mouse', '/articles/choosing-a-mouse')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_rf_items, fuzzphony_rf_items__vocab, fuzzphony_rf_items__next, fuzzphony_rf_items__changes, fuzzphony_rf_tenant, fuzzphony_rf_tenant__vocab, fuzzphony_rf_tenant__next, fuzzphony_rf_tenant__changes, fuzzphony_rf_site, fuzzphony_rf_site__vocab, fuzzphony_rf_site__next, fuzzphony_rf_site__changes, fuzzphony_rf_plain, fuzzphony_rf_plain__vocab, fuzzphony_rf_plain__next, fuzzphony_rf_plain__changes, rf_item, rf_article CASCADE');
    }

    private function build(?SearchGate $gate = null): Fuzzphony
    {
        $items = IndexDefinition::builder('rf_items')
            ->fromQuery('SELECT id, title, slug, secret, price, qty, ratio, live, released, created, tags, meta FROM rf_item')
            ->field('title', 'A', fuzzy: true)
            ->field('secret', 'C', granted: 'ROLE_BOSS')
            ->language('english')
            ->sync('manual')
            ->returnable('title', 'slug', 'secret', 'price', 'qty', 'ratio', 'live', 'released', 'created', 'tags', 'meta')
            ->build();
        $tenant = IndexDefinition::builder('rf_tenant')
            ->fromQuery('SELECT id, title, slug, tid FROM rf_item')
            ->field('title', 'A')
            ->filter('tid', 'int')
            ->tenant('tid')
            ->language('english')
            ->sync('manual')
            ->returnable('slug')
            ->build();
        $site = IndexDefinition::builder('rf_site')
            ->source('item', 'SELECT id, title, slug, price FROM rf_item', returnable: ['slug', 'price'])
            ->source('article', 'SELECT id, headline AS title, url FROM rf_article', idType: 'uuid', returnable: ['url'])
            ->field('title', 'A')
            ->language('english')
            ->sync('manual')
            ->build();
        $plain = IndexDefinition::builder('rf_plain')->fromQuery('SELECT id, title FROM rf_item')->field('title', 'A')->sync('manual')->build();

        $fz = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$items, $tenant, $site, $plain]), $gate);
        $fz->schema()->apply($this->connection);
        foreach (['rf_items', 'rf_tenant', 'rf_site', 'rf_plain'] as $name) {
            $fz->reindex($name);
        }

        return $fz;
    }

    private static function guarded(): SearchGate
    {
        return new SearchGate(guards: [new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return $actor->user === 'boss' ? Access::all() : Access::only(array_values(array_diff($index->fieldNames(), ['secret'])));
            }
        }]);
    }

    /** @return array<int|string, Hit> */
    private static function byId(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[$hit->id] = $hit;
        }

        return $out;
    }

    /** @return list<string> */
    private static function labels(array $statements): array
    {
        return array_map(static fn(array $s): string => $s['label'], $statements);
    }

    public function testTheSourceValuesComeBackTyped(): void
    {
        $fz = $this->build();
        $hit = $fz->in('rf_items')->query('wireless')->with('title', 'slug', 'price', 'qty', 'ratio', 'live', 'released', 'created', 'tags', 'meta')->get()->hits[0];

        self::assertSame(1, $hit->id);
        self::assertSame(['title' => 'Wireless mouse', 'slug' => 'wireless-mouse', 'price' => 19.9, 'qty' => 5, 'ratio' => 0.5, 'live' => true, 'released' => '2026-01-02', 'created' => '2026-01-02T03:04:05+00:00', 'tags' => '["a","b"]', 'meta' => '{"k":[1,2]}'], $hit->data);
        self::assertSame($hit->data, $hit->toArray()['data']);
        self::assertStringContainsString('"data":{"title":"Wireless mouse"', json_encode($hit, JSON_THROW_ON_ERROR));

        $null = $fz->in('rf_items')->query('wired')->with('price', 'live', 'tags')->get()->hits[0];
        self::assertSame(['price' => null, 'live' => false, 'tags' => null], $null->data);
    }

    public function testWithoutArgumentsEveryReturnableColumnComesBack(): void
    {
        $fz = $this->build();
        $data = $fz->in('rf_tenant')->forTenant(1)->query('mouse')->with()->get()->hits[0]->data;
        self::assertSame(['slug' => 'wireless-mouse'], $data);
        $fz2 = $fz->in('rf_items')->query('wireless')->with('slug')->with('qty');
        self::assertSame(['slug', 'qty'], array_keys($fz2->get()->hits[0]->data));
    }

    public function testDefaultSearchesAreUntouched(): void
    {
        $fz = $this->build();
        $plain = $fz->in('rf_items')->query('mouse');
        $without = $plain->explain();
        $with = $plain->with('slug')->explain();

        self::assertSame([], $without->result->hits[0]->data);
        self::assertArrayNotHasKey('data', $without->result->hits[0]->toArray());
        self::assertStringNotContainsString('fields', implode('|', self::labels($without->statements)));
        self::assertSame(self::labels($without->statements), array_slice(self::labels($with->statements), 0, count($without->statements)));
        self::assertSame(['returned fields: rf_items'], array_slice(self::labels($with->statements), count($without->statements)));
        self::assertSame($without->result->ids(), $with->result->ids());
        self::assertSame($without->result->hits[0]->score, $with->result->hits[0]->score);
        self::assertSame($without->plan, $with->plan);
        $statement = $with->statements[array_key_last($with->statements)];
        self::assertStringContainsString('"slug"', $statement['sql']);
        self::assertStringNotContainsString('mouse', $statement['sql'], 'the ids are bound, never inlined');
        self::assertSame(['p0' => '{1}'], $statement['params']);
    }

    public function testANameThatIsNotReturnableIsRefused(): void
    {
        $fz = $this->build();
        try {
            $fz->in('rf_items')->query('mouse')->with('slug', 'nope')->get();
            self::fail('InvalidQuery expected');
        } catch (InvalidQuery $e) {
            self::assertStringContainsString('cannot return "nope"', $e->getMessage());
            self::assertStringContainsString('Returnable columns: title, slug, secret, price', $e->getMessage());
        }
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('has no returnable columns');
        $fz->in('rf_plain')->query('mouse')->with()->get();
    }

    public function testAGrantedFieldIsReturnedOnlyToWhoHoldsIt(): void
    {
        $fz = $this->build(self::guarded());
        $boss = $fz->in('rf_items')->query('wireless')->asActor(new Actor(user: 'boss'));
        self::assertSame('margin zebrafish', $boss->with('title', 'secret')->get()->hits[0]->data['secret']);
        self::assertArrayHasKey('secret', $boss->with()->get()->hits[0]->data);

        $ada = $fz->in('rf_items')->query('wireless')->asActor(new Actor(user: 'ada'));
        self::assertSame(['title' => 'Wireless mouse'], $ada->with('title')->get()->hits[0]->data);
        self::assertArrayNotHasKey('secret', $ada->with()->get()->hits[0]->data, 'all returnable = the ones she may see');
        try {
            $ada->with('secret')->get();
            self::fail('InvalidQuery expected');
        } catch (InvalidQuery $hidden) {
            try {
                $ada->with('nope')->get();
                self::fail('InvalidQuery expected');
            } catch (InvalidQuery $unknown) {
                self::assertSame(str_replace('"nope"', '"secret"', $unknown->getMessage()), $hidden->getMessage(), 'a hidden name fails like an unknown one');
                self::assertStringNotContainsString('secret,', $hidden->getMessage());
                self::assertStringNotContainsString(', secret', $hidden->getMessage());
            }
        }
        $narrow = $boss->restrictedToFields('title');
        self::assertSame(['title' => 'Wireless mouse'], $narrow->with('title')->get()->hits[0]->data);
        $this->expectException(InvalidQuery::class);
        $narrow->with('secret')->get();
    }

    public function testASystemSearchSeesEveryReturnableColumn(): void
    {
        $fz = $this->build(self::guarded());

        self::assertSame('margin zebrafish', $fz->in('rf_items')->query('wireless')->asSystem()->with('secret')->get()->hits[0]->data['secret']);
    }

    public function testTheTenantIsRepeatedOnTheSource(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rf_tenant')->forTenant(1)->query('mouse')->with('slug')->explain();
        $statement = $explained->statements[array_key_last($explained->statements)];
        self::assertStringContainsString('d."tid"::bigint = CAST(', $statement['sql']);
        self::assertContains(1, $statement['params']);

        // the sidecar still says tenant 1, the source moved the row to tenant 2: no data for it
        $this->connection->execute('UPDATE rf_item SET tid = 2 WHERE id = 1');
        $hits = $fz->in('rf_tenant')->forTenant(1)->query('mouse')->with('slug')->get()->hits;
        self::assertSame(1, $hits[0]->id);
        self::assertSame([], $hits[0]->data);
    }

    public function testAVanishedRowHasNoData(): void
    {
        $fz = $this->build();
        $this->connection->execute('DELETE FROM rf_item WHERE id = 1');
        $hits = $fz->in('rf_items')->query('mouse')->with('slug')->get()->hits;

        self::assertSame([1], array_map(static fn(Hit $h): int|string => $h->id, $hits));
        self::assertSame([], $hits[0]->data);
    }

    public function testAMultiSourceIndexReadsEachSourceOnceAndNullsWhatASourceDoesNotReturn(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rf_site')->query('mouse')->with('slug', 'price', 'url')->explain();
        $hits = self::byId($explained->result->hits);

        $labels = array_slice(self::labels($explained->statements), -2);
        sort($labels);
        self::assertSame(['returned fields: article', 'returned fields: item'], $labels);
        self::assertSame(['slug' => 'wireless-mouse', 'price' => 19.9, 'url' => null], $hits[1]->data);
        self::assertSame(['slug' => null, 'price' => null, 'url' => '/articles/choosing-a-mouse'], $hits[self::ARTICLE]->data);
        self::assertSame('article', $hits[self::ARTICLE]->kind);

        $only = $fz->in('rf_site')->query('keyboard')->with('slug')->explain();
        self::assertSame(1, count(array_filter(self::labels($only->statements), static fn(string $l): bool => str_starts_with($l, 'returned fields'))), 'one statement per source on the page');
    }

    public function testAWithoutResultsRunsNoStatement(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rf_items')->query('qqqqzzzz')->thresholds(['typo' => ['mode' => 'never']])->with('slug')->explain();

        self::assertSame([], $explained->result->hits);
        self::assertSame([], array_filter(self::labels($explained->statements), static fn(string $l): bool => str_starts_with($l, 'returned fields')));
    }

    public function testAColumnTheSourceDoesNotReturnFailsTheSchemaApply(): void
    {
        $bad = IndexDefinition::builder('rf_items')->fromQuery('SELECT id, title FROM rf_item')->field('title', 'A')->sync('manual')->returnable('missing_col')->build();
        $fz = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$bad]));
        try {
            $fz->schema()->apply($this->connection);
            self::fail('InvalidDefinition expected');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Source "rf_items" does not return column "missing_col" (returnable column "missing_col")', $e->getMessage());
        }

        $multi = IndexDefinition::builder('rf_site')->source('item', 'SELECT id, title FROM rf_item', returnable: ['slug'])->field('title', 'A')->sync('manual')->build();
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('Source "item" does not return column "slug"');
        (new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$multi])))->schema()->apply($this->connection);
    }
}
