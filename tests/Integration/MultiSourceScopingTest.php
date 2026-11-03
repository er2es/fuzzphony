<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** Tenants and restricted viewers on a multi-source index: the rules are those of a single-source index, for every kind. */
final class MultiSourceScopingTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_scoped, fuzzphony_scoped__vocab, fuzzphony_scoped__next, fuzzphony_scoped__changes, ms_s_one, ms_s_two, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
        $this->connection->execute('CREATE TABLE ms_s_one (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL, secret text NOT NULL, account integer NOT NULL)');
        $this->connection->execute('CREATE TABLE ms_s_two (id uuid PRIMARY KEY, headline text NOT NULL, text_body text NOT NULL, hush text NOT NULL, owner_id integer NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_scoped, fuzzphony_scoped__vocab, fuzzphony_scoped__next, fuzzphony_scoped__changes, ms_s_one, ms_s_two CASCADE');
    }

    private function fuzzphony(): Fuzzphony
    {
        $index = IndexDefinition::builder('scoped')
            ->source('one', 'SELECT id, title, body, secret, account FROM ms_s_one', watch: [new Watch('ms_s_one')])
            ->source('two', 'SELECT id, headline AS title, text_body AS body, hush AS secret, owner_id AS account FROM ms_s_two', watch: [new Watch('ms_s_two')], idType: 'uuid')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'B', fuzzy: true)
            ->field('secret', 'C', fuzzy: true, granted: 'ROLE_SECRET')
            ->filter('account', 'int')
            ->tenant('account')
            ->sync('manual')
            ->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);

        return $fuzzphony;
    }

    private function load(string $secret): void
    {
        $this->connection->execute('DELETE FROM ms_s_one');
        $this->connection->execute('DELETE FROM ms_s_two');
        $this->connection->execute("INSERT INTO ms_s_one VALUES (1, 'word1 word2', 'plain', :s, 1), (2, 'nothing', 'word1 word2', :s, 2), (3, 'word2 word1', 'zzz', :s, 1)", ['s' => $secret]);
        $this->connection->execute("INSERT INTO ms_s_two VALUES ('0b1d6f0e-0000-4000-8000-000000000001', 'word1 word2 guide', 'plain', :s, 1), ('0b1d6f0e-0000-4000-8000-000000000002', 'other', 'word1 word2', :s, 2)", ['s' => $secret]);
    }

    /**
     * @param list<\Fuzzphony\Core\Search\Hit> $hits
     *
     * @return list<string>
     */
    private static function keys(array $hits): array
    {
        return array_map(static fn(\Fuzzphony\Core\Search\Hit $h): string => $h->kind . ':' . $h->id, $hits);
    }

    public function testATenantNeverSeesAnotherTenantsRowsOfAnyKind(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->load('nothing');
        $fuzzphony->reindex('scoped');

        $one = self::keys($fuzzphony->in('scoped')->forTenant(1)->query('word1')->get()->hits);
        $two = self::keys($fuzzphony->in('scoped')->forTenant(2)->query('word1')->get()->hits);
        sort($one);
        sort($two);

        self::assertSame(['one:1', 'one:3', 'two:0b1d6f0e-0000-4000-8000-000000000001'], $one);
        self::assertSame(['one:2', 'two:0b1d6f0e-0000-4000-8000-000000000002'], $two);
        self::assertSame(['one:1', 'one:3', 'two:0b1d6f0e-0000-4000-8000-000000000001'], (function () use ($fuzzphony): array {
            $hits = self::keys($fuzzphony->in('scoped')->forTenant(1)->query('')->get()->hits);
            sort($hits);

            return $hits;
        })(), 'browse is scoped too');
    }

    public function testOmittingTheTenantIsRefused(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('requires forTenant()');

        $this->fuzzphony()->in('scoped')->query('word1')->get();
    }

    public function testAFacetOverTheTenantFilterIsRefused(): void
    {
        $this->expectException(InvalidQuery::class);

        $this->fuzzphony()->in('scoped')->forTenant(1)->query('word1')->facets('account')->get();
    }

    /** What a viewer without the granted field finds must not depend on that field, whatever the kind and however long it is. */
    public function testNothingTheGrantedFieldHoldsChangesWhatARestrictedViewerFinds(): void
    {
        $fuzzphony = $this->fuzzphony();
        $variants = ['empty' => '', 'long' => implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000))), 'words' => 'word1 word2 nothing plain'];
        $queries = ['"word1 word2"', 'word1', 'title:"word1 word2"', 'secret:word1', 'word1 -"word1 word2"', 'wrod1'];
        $outcomes = [];
        foreach ($variants as $variant => $secret) {
            $this->load($secret);
            $fuzzphony->reindex('scoped');
            foreach ($queries as $text) {
                foreach (['never', 'always'] as $mode) {
                    foreach ([1, 2] as $tenant) {
                        $explained = $fuzzphony->in('scoped')->forTenant($tenant)->query($text)->thresholds(['typo' => ['mode' => $mode]])->restrictedToFields('title', 'body')->limit(5)->explain();
                        $result = $explained->result;
                        $outcomes[$text . ' | ' . $mode . ' | ' . $tenant][$variant] = [
                            array_map(static fn($hit): array => [$hit->kind, $hit->id, round($hit->score, 9), round($hit->breakdown->textRank, 9)], $result->hits),
                            $result->total,
                            $result->usedFuzzy,
                            $result->warnings,
                            array_map(static fn(array $s): array => [$s['label'], $s['sql'], $s['params']], $explained->statements),
                        ];
                    }
                }
            }
        }
        self::assertNotSame([], $outcomes['"word1 word2" | never | 1']['empty'][0], 'the comparison is not empty');
        foreach ($outcomes as $case => $byVariant) {
            foreach ($byVariant as $variant => $outcome) {
                self::assertSame($byVariant['empty'], $outcome, sprintf('%s: the granted field %s changed the outcome', $case, $variant));
            }
        }
    }

    public function testAViewerWithoutTheGrantedFieldDoesNotFindItsWordsInAnyKind(): void
    {
        $fuzzphony = $this->fuzzphony();
        $this->load('classified');
        $fuzzphony->reindex('scoped');

        self::assertSame([], $fuzzphony->in('scoped')->forTenant(1)->query('classified')->restrictedToFields('title', 'body')->get()->hits);
        self::assertCount(3, $fuzzphony->in('scoped')->forTenant(1)->query('classified')->get()->hits, 'the unrestricted search finds them in every kind');
    }
}
