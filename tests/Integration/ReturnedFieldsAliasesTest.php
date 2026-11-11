<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\ManyValues;
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
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use PHPUnit\Framework\TestCase;

/**
 * with() follow-ups: aliases, the values of a many field, one statement per page. The security rules of 0.11 must hold on every new
 * path: only returnable names, a granted rule gates by name AND by column, the tenant is repeated on the source, values are bound.
 */
final class ReturnedFieldsAliasesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->drop();
        $this->connection->execute('CREATE TABLE rfa_item (id bigint PRIMARY KEY, title text NOT NULL, slug text, salary text, tid integer NOT NULL DEFAULT 1)');
        $this->connection->execute("INSERT INTO rfa_item VALUES (1, 'Wireless mouse', 'wireless-mouse', 'top-secret-1', 1), (2, 'Wireless keyboard', 'wireless-keyboard', 'top-secret-2', 2)");
        $this->connection->execute('CREATE TABLE rfa_tag (id serial PRIMARY KEY, item_id bigint NOT NULL, label text, hidden text)');
        $this->connection->execute("INSERT INTO rfa_tag (item_id, label, hidden) VALUES (1, 'zeta', 'h1'), (1, 'alpha', 'h2'), (1, 'mid', 'h3'), (1, NULL, 'h4'), (2, 'other-tenant-tag', 'h5')");
        $this->connection->execute('CREATE TABLE rfa_article (id uuid PRIMARY KEY, headline text NOT NULL, url text)');
        $this->connection->execute("INSERT INTO rfa_article VALUES ('0b1d6f0e-0000-4000-8000-000000000001', 'Wireless guide', '/guide')");
    }

    protected function tearDown(): void
    {
        $this->drop();
    }

    private function drop(): void
    {
        foreach (['rfa_a', 'rfa_t', 'rfa_m', 'rfa_g'] as $name) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS fuzzphony_%1$s, fuzzphony_%1$s__vocab, fuzzphony_%1$s__next, fuzzphony_%1$s__changes, fuzzphony_%1$s__values CASCADE', $name));
        }
        $this->connection->execute('DROP TABLE IF EXISTS rfa_item, rfa_tag, rfa_article CASCADE');
    }

    private function build(): Fuzzphony
    {
        $many = new ManyValues('rfa_tag', 'item_id', 'label', 3);
        // aliases: url reads slug, pay reads the column of the granted field "pay" (column salary), tags is a many field
        $a = IndexDefinition::builder('rfa_a')
            ->fromQuery('SELECT id, title, slug, salary FROM rfa_item')
            ->field('title', 'A')
            ->field('pay', 'C', column: 'salary', granted: 'ROLE_HR')
            ->field('tags', 'C', many: $many, granted: 'ROLE_HR')
            ->language('english')
            ->sync('manual')
            ->returnable(['url' => 'slug', 'title', 'wage' => 'salary', 'labels' => 'tags'], 'tags')
            ->build();
        $t = IndexDefinition::builder('rfa_t')
            ->fromQuery('SELECT id, title, slug, tid FROM rfa_item')
            ->field('title', 'A')
            ->filter('tid', 'int')
            ->tenant('tid')
            ->language('english')
            ->sync('manual')
            ->returnable(['link' => 'slug'])
            ->build();
        $m = IndexDefinition::builder('rfa_m')
            ->source('item', 'SELECT id, title, slug, tid FROM rfa_item', returnable: ['link' => 'slug'])
            ->source('article', 'SELECT id, headline AS title, url FROM rfa_article', idType: 'uuid', returnable: ['link' => 'url'])
            ->field('title', 'A')
            ->language('english')
            ->sync('manual')
            ->build();
        $g = IndexDefinition::builder('rfa_g')
            ->fromQuery('SELECT id, title, slug FROM rfa_item')
            ->field('title', 'A')
            ->field('tags', 'C', many: $many, granted: 'ROLE_HR')
            ->language('english')
            ->sync('manual')
            ->returnable('title', 'tags')
            ->build();

        $gate = new SearchGate(guards: [new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return $actor->user === 'hr' ? Access::all() : Access::only(array_values(array_diff($index->fieldNames(), ['pay', 'tags'])));
            }
        }]);
        $fz = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$a, $t, $m, $g]), $gate);
        $fz->schema()->apply($this->connection);
        foreach (['rfa_a', 'rfa_t', 'rfa_m', 'rfa_g'] as $name) {
            $fz->reindex($name);
        }

        return $fz;
    }

    /**
     * @param list<Hit> $hits
     *
     * @return array<int|string, Hit>
     */
    private static function byId(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[$hit->id] = $hit;
        }

        return $out;
    }

    public function testAnAliasReturnsTheColumnUnderItsName(): void
    {
        $fz = $this->build();
        $hr = new Actor(user: 'hr');
        $hit = $fz->in('rfa_a')->query('mouse')->asActor($hr)->with('url', 'title')->get()->hits[0];

        self::assertEquals(['url' => 'wireless-mouse', 'title' => 'Wireless mouse'], $hit->data);
        // the real column name is not returnable just because an alias reads it
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('cannot return "slug"');
        $fz->in('rfa_a')->query('mouse')->asActor($hr)->with('slug')->get();
    }

    public function testAnAliasOfAGrantedColumnIsHiddenFromWhoDoesNotHoldTheField(): void
    {
        $fz = $this->build();
        $clerk = $fz->in('rfa_a')->query('mouse')->asActor(new Actor(user: 'clerk'));
        $hr = $fz->in('rfa_a')->query('mouse')->asActor(new Actor(user: 'hr'));

        self::assertSame('top-secret-1', $hr->with('wage')->get()->hits[0]->data['wage']);
        self::assertArrayNotHasKey('wage', $clerk->with()->get()->hits[0]->data, 'with() of everything: the aliased granted column is left out');
        self::assertArrayNotHasKey('labels', $clerk->with()->get()->hits[0]->data);
        try {
            $clerk->with('wage')->get();
            self::fail('an alias reached a granted column');
        } catch (InvalidQuery $hidden) {
            try {
                $clerk->with('nope')->get();
                self::fail('InvalidQuery expected');
            } catch (InvalidQuery $unknown) {
                self::assertSame(str_replace('"nope"', '"wage"', $unknown->getMessage()), $hidden->getMessage(), 'a hidden alias fails like an unknown name');
                self::assertStringNotContainsString('wage', str_replace('"wage"', '', $hidden->getMessage()));
            }
        }
        // restrictedToFields() narrows the granted field away from a viewer who holds it
        $this->expectException(InvalidQuery::class);
        $hr->restrictedToFields('title')->with('wage')->get();
    }

    public function testNoStatementOfARestrictedViewerNamesAGrantedColumn(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rfa_a')->query('mouse')->asActor(new Actor(user: 'clerk'))->with()->explain();
        $sql = implode("\n", array_map(static fn(array $s): string => $s['sql'], $explained->statements));

        self::assertStringContainsString('"slug"', $sql);
        self::assertStringNotContainsString('"salary"', $sql);
        self::assertStringNotContainsString('rfa_tag', $sql);
    }

    public function testAnAliasCalledLikeAGrantedFieldIsHiddenToo(): void
    {
        $index = IndexDefinition::builder('rfa_n')
            ->fromQuery('SELECT id, title, slug FROM rfa_item')
            ->field('title', 'A')
            ->field('pay', 'C', column: 'salary', granted: 'ROLE_HR')
            ->returnable(['pay' => 'title'])
            ->sync('manual')
            ->build();

        $query = (new \Fuzzphony\Core\Query\SearchQuery('x'))->restrictedTo(['title'])->returning(['pay']);
        $this->expectException(InvalidQuery::class);
        \Fuzzphony\Engine\Postgres\SourceFields::resolve($index, $query);
    }

    public function testTheValuesOfAManyFieldComeBackAsAListInTheOrderOfTheIndexWithinTheLimit(): void
    {
        $fz = $this->build();
        $hr = $fz->in('rfa_a')->query('mouse')->asActor(new Actor(user: 'hr'));
        $explained = $hr->with('tags', 'labels', 'url')->explain();
        $data = $explained->result->hits[0]->data;

        // limit 3: the first three non-null values by (value, pk): alpha, mid, zeta (NULL is skipped, the 4th row is cut by nothing)
        self::assertSame(['alpha', 'mid', 'zeta'], $data['tags']);
        self::assertSame($data['tags'], $data['labels'], 'an alias of a many field returns the same list');
        self::assertSame('wireless-mouse', $data['url']);
        self::assertSame(1, count(array_filter(array_map(static fn(array $s): string => $s['label'], $explained->statements), static fn(string $l): bool => str_starts_with($l, 'returned fields'))), 'the many values ride in the one statement');
        self::assertStringNotContainsString('alpha', implode('|', array_map(static fn(array $s): string => $s['sql'], $explained->statements)), 'no value is inlined');

        $none = $fz->in('rfa_a')->query('wireless')->asActor(new Actor(user: 'hr'))->with('tags')->get()->hits;
        self::assertSame([['alpha', 'mid', 'zeta'], ['other-tenant-tag']], array_values(array_map(static fn(Hit $h): mixed => $h->data['tags'], self::sorted($none))));
        $this->connection->execute('DELETE FROM rfa_tag WHERE item_id = 1');
        self::assertSame([], $hr->with('tags')->get()->hits[0]->data['tags'], 'no child row: an empty list, never null');
    }

    /**
     * @param list<Hit> $hits
     *
     * @return list<Hit>
     */
    private static function sorted(array $hits): array
    {
        usort($hits, static fn(Hit $a, Hit $b): int => $a->id <=> $b->id);

        return $hits;
    }

    public function testAGrantedManyFieldIsReturnedOnlyToWhoHoldsIt(): void
    {
        $fz = $this->build();
        $clerk = $fz->in('rfa_g')->query('mouse')->asActor(new Actor(user: 'clerk'));

        self::assertSame(['title' => 'Wireless mouse'], $clerk->with()->get()->hits[0]->data);
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('cannot return "tags"');
        try {
            $hr = $fz->in('rfa_g')->query('mouse')->asActor(new Actor(user: 'hr'));
            self::assertSame(['alpha', 'mid', 'zeta'], $hr->with('tags')->get()->hits[0]->data['tags']);
        } catch (InvalidQuery) {
            self::fail('the holder must get the many values');
        }
        $clerk->with('tags')->get();
    }

    public function testATenantBActorNeverGetsTenantARowsThroughAnAlias(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rfa_t')->forTenant(2)->query('wireless')->with('link')->explain();
        $statements = $explained->statements;
        $statement = end($statements);
        self::assertNotFalse($statement);

        self::assertSame([2], array_map(static fn(Hit $h): int|string => $h->id, $explained->result->hits));
        self::assertSame('wireless-keyboard', $explained->result->hits[0]->data['link']);
        self::assertStringContainsString('d."tid"::bigint = CAST(', $statement['sql']);
        self::assertContains(2, $statement['params']);

        // the sidecar says tenant 2 but the source moved the row to tenant 1: the repeated condition returns nothing for tenant 2
        $this->connection->execute('UPDATE rfa_item SET tid = 1 WHERE id = 2');
        $hits = $fz->in('rfa_t')->forTenant(2)->query('wireless')->with('link')->get()->hits;
        self::assertSame([], $hits[0]->data, 'tenant B gets no data of a row that is no longer his');
    }

    public function testTheMultiSourceStatementIsOneUnionWithEverySourcesOwnColumn(): void
    {
        $fz = $this->build();
        $explained = $fz->in('rfa_m')->query('wireless')->with('link')->explain();
        $hits = self::byId($explained->result->hits);
        $returned = array_values(array_filter($explained->statements, static fn(array $s): bool => str_starts_with($s['label'], 'returned fields')));

        self::assertCount(1, $returned);
        self::assertSame('wireless-mouse', $hits[1]->data['link']);
        self::assertSame('/guide', $hits['0b1d6f0e-0000-4000-8000-000000000001']->data['link']);
        self::assertStringContainsString('UNION ALL', $returned[0]['sql']);
        self::assertStringContainsString('d."slug"', $returned[0]['sql']);
        self::assertStringContainsString('d."url"', $returned[0]['sql']);
        self::assertCount(2, $returned[0]['params'], 'one bound id list per source');
    }

    public function testTheAliasShapeIsValidated(): void
    {
        foreach ([['a"b' => 'slug'], ['url' => 'bad col'], ['url' => 'slug', 'url ' => 'x']] as $bad) {
            try {
                IndexDefinition::builder('x')->fromTable('item')->field('t', 'A')->returnable($bad)->build();
                self::fail('InvalidDefinition expected');
            } catch (InvalidDefinition $e) {
                self::assertStringContainsString('not a valid column name', $e->getMessage());
            }
        }
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('declared with two different columns');
        IndexDefinition::builder('x')->fromTable('item')->field('t', 'A')->returnable(['url' => 'slug'])->returnable(['url' => 'other'])->build();
    }

    public function testTheYamlMapAndTheBuilderAgreeAndTheFingerprintMovesOnlyWithAliases(): void
    {
        $yaml = (new ArrayDefinitionLoader())->load('items', ['source' => ['table' => 'item'], 'fields' => ['title' => 'A'], 'returnable' => ['url' => 'slug', 'title' => 'title', 'price']]);
        $built = IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->returnable(['url' => 'slug', 'title', 'price'])->build();
        $plain = IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->returnable('slug', 'title', 'price')->build();

        self::assertEquals($built->returnableAs, $yaml->returnableAs);
        self::assertSame(['url' => 'slug'], $built->returnableAs);
        self::assertSame(['title', 'price', 'url'], $built->returnableNames());
        self::assertSame('slug', $built->returnableColumn('items', 'url'));
        self::assertNull($built->returnableColumn('items', 'slug'));
        self::assertNotSame(Fingerprint::definition($built), Fingerprint::definition($plain));
        self::assertSame(Fingerprint::definition($plain), Fingerprint::definition(IndexDefinition::builder('items')->fromTable('item')->field('title', 'A')->returnable('slug', 'title', 'price')->build()));
    }
}
