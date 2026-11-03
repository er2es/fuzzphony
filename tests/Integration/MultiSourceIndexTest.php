<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** An index with several sources (product with int ids, article with uuids, a legacy table with a composite text id) in one ranking. */
final class MultiSourceIndexTest extends TestCase
{
    private const string ARTICLE_A = '0b1d6f0e-0000-4000-8000-000000000001';
    private const string ARTICLE_B = '0b1d6f0e-0000-4000-8000-000000000002';

    private Connection $connection;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->engine = new PostgresEngine($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_site, fuzzphony_site__next, fuzzphony_site__changes, fuzzphony_site__vocab, ms_product, ms_article, ms_legacy, ms_tag, fuzzphony_queue, fuzzphony_watches, fuzzphony_watch_log, fuzzphony_meta CASCADE');
        $this->connection->execute('CREATE TABLE ms_tag (id bigint PRIMARY KEY, label text NOT NULL)');
        $this->connection->execute('CREATE TABLE ms_product (id bigint PRIMARY KEY, name text NOT NULL, descr text, account integer NOT NULL, tag_id bigint)');
        $this->connection->execute('CREATE TABLE ms_article (id uuid PRIMARY KEY, headline text NOT NULL, text_body text, account integer NOT NULL, tag_id bigint)');
        $this->connection->execute('CREATE TABLE ms_legacy (pk serial PRIMARY KEY, region text NOT NULL, num integer NOT NULL, label text NOT NULL, notes text, account integer NOT NULL)');
        $this->connection->execute("INSERT INTO ms_tag VALUES (1, 'wireless'), (2, 'ergonomic')");
        $this->connection->execute("INSERT INTO ms_product VALUES (1, 'Mouse', 'A quiet mouse', 1, 1), (2, 'Keyboard', 'Mechanical keyboard', 1, 2), (3, 'Monitor', 'A mouse pad is not included', 2, NULL)");
        $this->connection->execute("INSERT INTO ms_article VALUES ('" . self::ARTICLE_A . "', 'Choosing a mouse', 'How to pick a mouse', 1, 1), ('" . self::ARTICLE_B . "', 'Cleaning a keyboard', 'Keys and dust', 2, NULL)");
        $this->connection->execute("INSERT INTO ms_legacy (region, num, label, notes, account) VALUES ('EU', 7, 'Old mouse model', 'discontinued', 1), ('US', 7, 'Old keyboard model', NULL, 2)");
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_site, fuzzphony_site__next, fuzzphony_site__changes, fuzzphony_site__vocab, ms_product, ms_article, ms_legacy, ms_tag CASCADE');
    }

    private function definition(string $sync = 'queue', string $level = 'statement'): IndexDefinition
    {
        return self::builder($sync)->triggerLevel($level)->build();
    }

    private static function builder(string $sync = 'queue'): IndexBuilder
    {
        return IndexDefinition::builder('site')
            ->source(
                'product',
                'SELECT p.id, p.name AS title, concat_ws(\' \', p.descr, t.label) AS body, p.account FROM ms_product p LEFT JOIN ms_tag t ON t.id = p.tag_id',
                watch: [new Watch('ms_product'), new Watch('ms_tag', 'SELECT id FROM ms_product WHERE tag_id = :id')],
            )
            ->source(
                'article',
                'SELECT a.id, a.headline AS title, concat_ws(\' \', a.text_body, t.label) AS body, a.account FROM ms_article a LEFT JOIN ms_tag t ON t.id = a.tag_id',
                watch: [new Watch('ms_article'), new Watch('ms_tag', 'SELECT id FROM ms_article WHERE tag_id = :id')],
                idType: 'uuid',
            )
            ->source(
                'legacy',
                'SELECT l.region || \'-\' || l.num AS id, l.label AS title, l.notes AS body, l.account FROM ms_legacy l',
                watch: [new Watch('ms_legacy', 'SELECT l.region || \'-\' || l.num FROM ms_legacy l WHERE l.pk = :id', 'pk')],
                idType: 'string',
            )
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'C')
            ->filter('account', 'int')
            ->sync($sync);
    }

    private function install(IndexDefinition $index): Fuzzphony
    {
        $fuzzphony = new Fuzzphony($this->engine, new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex($index->name);

        return $fuzzphony;
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_map(static fn(array $r): string => Coerce::str($r['id']), $this->connection->fetchAll('SELECT id FROM fuzzphony_site ORDER BY id'));
    }

    /** @return array<string, string> key => indexed_at */
    private function stamps(): array
    {
        return array_column($this->connection->fetchAll('SELECT id, indexed_at::text AS at FROM fuzzphony_site ORDER BY id'), 'at', 'id');
    }

    private function converge(IndexDefinition $index): void
    {
        (new Worker($this->engine))->runOnce([$index]);
    }

    public function testEverySourceIsIndexedAndSearchedInOneRanking(): void
    {
        $index = $this->definition();
        $fuzzphony = $this->install($index);

        self::assertSame(['article:' . self::ARTICLE_A, 'article:' . self::ARTICLE_B, 'legacy:EU-7', 'legacy:US-7', 'product:1', 'product:2', 'product:3'], $this->keys());
        $row = $this->connection->fetchAll("SELECT kind, src_id, pg_typeof(id)::text AS type FROM fuzzphony_site WHERE id = 'product:2'")[0];
        self::assertSame(['kind' => 'product', 'src_id' => '2', 'type' => 'text'], $row);

        $hits = $fuzzphony->in('site')->query('mouse')->get()->hits;
        $found = [];
        foreach ($hits as $hit) {
            $found[$hit->kind . ':' . $hit->id] = $hit->id;
        }
        self::assertSame(1, $found['product:1'], 'a product id stays an int');
        self::assertSame(self::ARTICLE_A, $found['article:' . self::ARTICLE_A], 'an article id stays its uuid');
        self::assertSame('EU-7', $found['legacy:EU-7'], 'a legacy id stays the composite text of its source');
        $scores = array_map(static fn($h): float => $h->score, $hits);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores, 'one ranking over all kinds');
    }

    public function testFiltersFacetsHighlightAndTypoRunOverAllKinds(): void
    {
        $fuzzphony = $this->install($this->definition());

        $result = $fuzzphony->in('site')->query('mouse')->where('account', 1)->get();
        self::assertSame(['article', 'legacy', 'product'], $this->sortedKinds($result->hits));
        $typo = $fuzzphony->in('site')->query('mose')->get();
        self::assertNotSame([], $typo->hits, 'typo tolerance finds the mouse documents of every kind');
        $highlighted = $fuzzphony->in('site')->query('mouse')->highlight('title')->get()->hits;
        self::assertStringContainsString('<mark>', implode('', array_map(static fn($h): string => implode('', $h->highlights), $highlighted)));
        $facets = $fuzzphony->in('site')->query('mouse')->facets('account')->get()->facets;
        self::assertNotSame([], $facets);
    }

    /** @param list<\Fuzzphony\Core\Search\Hit> $hits @return list<string> */
    private function sortedKinds(array $hits): array
    {
        $kinds = array_values(array_unique(array_map(static fn($h): string => $h->kind, $hits)));
        sort($kinds);

        return $kinds;
    }

    #[DataProvider('syncModes')]
    public function testAChangeRefreshesOnlyItsOwnSource(string $sync, string $level): void
    {
        $index = $this->definition($sync, $level);
        $fuzzphony = $this->install($index);
        $before = $this->stamps();

        $this->connection->execute("UPDATE ms_product SET name = 'Trackball' WHERE id = 2");
        $this->converge($index);

        $after = $this->stamps();
        self::assertNotSame($before['product:2'], $after['product:2']);
        unset($before['product:2'], $after['product:2']);
        self::assertSame($before, $after, 'no other row was rewritten');
        self::assertSame([2], $this->productIds($fuzzphony, 'trackball'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function syncModes(): iterable
    {
        yield 'queue, statement level (the shared trigger)' => ['queue', 'statement'];
        yield 'queue, row level' => ['queue', 'row'];
        yield 'trigger, statement level' => ['trigger', 'statement'];
        yield 'trigger, row level' => ['trigger', 'row'];
    }

    /** @return list<int|string> */
    private function productIds(Fuzzphony $fuzzphony, string $query): array
    {
        $ids = [];
        foreach ($fuzzphony->in('site')->query($query)->get()->hits as $hit) {
            if ($hit->kind === 'product') {
                $ids[] = $hit->id;
            }
        }

        return $ids;
    }

    #[DataProvider('syncModes')]
    public function testATableReadByTwoSourcesRefreshesBoth(string $sync, string $level): void
    {
        $index = $this->definition($sync, $level);
        $fuzzphony = $this->install($index);
        $before = $this->stamps();

        $this->connection->execute("UPDATE ms_tag SET label = 'cordless' WHERE id = 1");
        $this->converge($index);

        $after = $this->stamps();
        $changed = array_keys(array_filter($after, static fn(string $at, string $key): bool => $at !== $before[$key], ARRAY_FILTER_USE_BOTH));
        sort($changed);
        self::assertSame(['article:' . self::ARTICLE_A, 'product:1'], $changed);
        self::assertSame([1], $this->productIds($fuzzphony, 'cordless'));
        self::assertSame([], $this->productIds($fuzzphony, 'wireless'));
    }

    public function testDeletingARowRemovesOnlyItsKey(): void
    {
        $index = $this->definition();
        $this->install($index);

        $this->connection->execute("DELETE FROM ms_article WHERE id = '" . self::ARTICLE_B . "'");
        $this->converge($index);

        self::assertNotContains('article:' . self::ARTICLE_B, $this->keys());
        self::assertCount(6, $this->keys());
    }

    public function testTruncatingOneSourcesTableEmptiesThatSourceOnly(): void
    {
        $index = $this->definition();
        $this->install($index);

        $this->connection->execute('TRUNCATE ms_article');
        PostgresTestCase::drain($this->connection, $index);
        $this->converge($index);

        self::assertSame(['legacy:EU-7', 'legacy:US-7', 'product:1', 'product:2', 'product:3'], $this->keys());
    }

    public function testTruncatingAJoinedTableRebuildsThatSourceOnly(): void
    {
        $index = $this->definition();
        $this->install($index);
        $before = $this->stamps();
        $this->connection->execute("INSERT INTO ms_product VALUES (9, 'Webcam', 'HD webcam', 1, NULL)");

        // ms_tag is read by the product and the article source: the truncate asks for a rebuild of each, never of the legacy source
        $this->connection->execute('TRUNCATE ms_tag CASCADE');
        PostgresTestCase::drain($this->connection, $index);
        self::assertSame(
            ['*:article', '*:product'],
            array_map(static fn(array $r): string => Coerce::str($r['doc_id']), $this->connection->fetchAll("SELECT doc_id FROM fuzzphony_queue WHERE index_name = 'site' AND starts_with(doc_id, '*') ORDER BY doc_id")),
        );
        $this->converge($index);

        $after = $this->stamps();
        self::assertSame($before['legacy:EU-7'], $after['legacy:EU-7'], 'the legacy source was not rebuilt');
        self::assertArrayHasKey('product:9', $after, 'the product source was rebuilt in place and found the new row');
        self::assertNotSame($before['product:1'], $after['product:1']);
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM fuzzphony_queue WHERE index_name = 'site'")), 'the requests are done');
    }

    public function testReindexOfOneSourceRewritesItsRowsOnly(): void
    {
        $index = $this->definition();
        $fuzzphony = $this->install($index);
        $before = $this->stamps();
        $this->connection->execute("INSERT INTO ms_product VALUES (4, 'Webcam', 'HD webcam', 1, NULL)");
        $this->connection->execute("UPDATE ms_product SET name = 'Gamepad' WHERE id = 1");
        $this->connection->execute("DELETE FROM ms_product WHERE id = 3");
        $this->connection->execute('TRUNCATE fuzzphony_queue');

        $result = $fuzzphony->reindex('site', new ReindexOptions(source: 'product'));

        self::assertSame(['product' => 3], $result->perSource);
        self::assertFalse($result->swapped);
        $after = $this->stamps();
        self::assertSame(['article:' . self::ARTICLE_A, 'article:' . self::ARTICLE_B, 'legacy:EU-7', 'legacy:US-7', 'product:1', 'product:2', 'product:4'], array_keys($after));
        self::assertSame($before['legacy:US-7'], $after['legacy:US-7']);
        self::assertSame($before['article:' . self::ARTICLE_A], $after['article:' . self::ARTICLE_A]);
        self::assertSame([1], $this->productIds($fuzzphony, 'gamepad'));
    }

    public function testAFullReindexSwapsAllSourcesIn(): void
    {
        $index = $this->definition();
        $fuzzphony = $this->install($index);
        $this->connection->execute("INSERT INTO ms_legacy (region, num, label, account) VALUES ('AS', 1, 'Old webcam', 1)");

        $result = $fuzzphony->reindex('site');

        self::assertTrue($result->swapped);
        $perSource = $result->perSource;
        ksort($perSource);
        self::assertSame(['article' => 2, 'legacy' => 3, 'product' => 3], $perSource);
        self::assertContains('legacy:AS-1', $this->keys());
        self::assertCount(8, $this->keys());
        self::assertSame(['kind', 'src_id'], array_column($this->connection->fetchAll("SELECT attname FROM pg_attribute WHERE attrelid = 'fuzzphony_site'::regclass AND attname IN ('kind', 'src_id') ORDER BY attname"), 'attname'));
    }

    public function testAnIdThatIsNotUniqueInsideItsSourceKeepsOneRow(): void
    {
        $index = IndexDefinition::builder('site')
            ->source('product', 'SELECT 1 AS id, name AS title, name AS body FROM ms_product', watch: [new Watch('ms_product')])
            ->source('legacy', 'SELECT 1 AS id, label AS title, label AS body FROM ms_legacy', watch: [new Watch('ms_legacy', 'SELECT 1 FROM ms_legacy WHERE pk = :id', 'pk')])
            ->field('title', 'A')
            ->field('body', 'C')
            ->build();
        $this->install($index);

        self::assertSame(['legacy:1', 'product:1'], $this->keys());
    }

    public function testASourceThatLacksAColumnIsRefusedNamingSourceAndColumn(): void
    {
        $index = IndexDefinition::builder('site')
            ->source('product', 'SELECT id, name AS title, descr AS body, account FROM ms_product', watch: [new Watch('ms_product')])
            ->source('article', 'SELECT id, headline AS title, account FROM ms_article', watch: [new Watch('ms_article')], idType: 'uuid')
            ->field('title', 'A')
            ->field('body', 'C')
            ->filter('account', 'int')
            ->build();

        try {
            (new Fuzzphony($this->engine, new IndexRegistry([$index])))->schema()->apply($this->connection);
            self::fail('expected InvalidDefinition');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Source "article" does not return column "body"', $e->getMessage());
            self::assertStringNotContainsString('Source "product"', $e->getMessage());
        }
    }

    public function testTheDoctorChecksEachSourceAndCountsThemSeparately(): void
    {
        $index = $this->definition();
        $fuzzphony = $this->install($index);
        $this->connection->execute("DELETE FROM fuzzphony_site WHERE kind = 'legacy'");

        $report = $fuzzphony->inspect('site', new \Fuzzphony\Core\Inspection\InspectOptions(deep: true));
        $names = array_map(static fn($c): string => $c->name, $report->checks);
        self::assertContains('Source "article": Source', $names);
        self::assertContains('Source "legacy": Id column', $names);
        $coverage = array_values(array_filter($report->checks, static fn($c): bool => $c->name === 'Coverage'))[0];
        self::assertStringContainsString('legacy: 0 of 2 (exact)', $coverage->message);
        self::assertStringContainsString('product: 3 of 3 (exact)', $coverage->message);
        self::assertSame('warning', $coverage->status->value);
    }

    public function testHighlightsAndMatchExplanationsFindTheRowOfTheRightKind(): void
    {
        $fuzzphony = $this->install($this->definition());

        $hits = $fuzzphony->in('site')->query('mouse')->highlight('title')->explainMatches()->get()->hits;
        $byKey = [];
        foreach ($hits as $hit) {
            $byKey[$hit->kind . ':' . $hit->id] = $hit;
        }
        self::assertSame('<mark>Mouse</mark>', $byKey['product:1']->highlights['title']);
        self::assertSame('Choosing a <mark>mouse</mark>', $byKey['article:' . self::ARTICLE_A]->highlights['title']);
        self::assertSame('Old <mark>mouse</mark> model', $byKey['legacy:EU-7']->highlights['title']);
        self::assertNotSame([], $byKey['legacy:EU-7']->matches);
    }

    public function testRefreshByKeyAndTheSchemaCycle(): void
    {
        $index = $this->definition('manual');
        $fuzzphony = $this->install($index);
        $this->connection->execute("UPDATE ms_product SET name = 'Trackball' WHERE id = 2");

        self::assertSame(1, $fuzzphony->refresh('site', ['product:2']));
        self::assertSame([2], $this->productIds($fuzzphony, 'trackball'));
        // a key of a row the source does not have removes the document
        $this->connection->execute('DELETE FROM ms_product WHERE id = 3');
        $fuzzphony->refresh('site', ['product:3']);
        self::assertNotContains('product:3', $this->keys());

        $fuzzphony->schema()->apply($this->connection);
        $this->engine->dropSchema($index)->apply($this->connection);
        self::assertNull($this->connection->fetchValue("SELECT to_regclass('fuzzphony_site')"));
        self::assertSame(0, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM fuzzphony_watches WHERE index_name = 'site'")));
    }

    public function testAHealthyMultiSourceIndexHasNoDoctorError(): void
    {
        $fuzzphony = $this->install($this->definition());

        foreach ($fuzzphony->inspect('site')->checks as $check) {
            self::assertNotSame('error', $check->status->value, $check->name . ': ' . $check->message);
        }
        $watches = $this->connection->fetchAll("SELECT source, tbl FROM fuzzphony_watches WHERE index_name = 'site' ORDER BY watch_no");
        self::assertSame(['product', 'product', 'article', 'article', 'legacy'], array_column($watches, 'source'));
    }

    public function testTheReindexCommandRebuildsOneSourceAndReportsEachCount(): void
    {
        $fuzzphony = $this->install($this->definition());
        $tester = new CommandTester(new ReindexCommand($fuzzphony));

        self::assertSame(0, $tester->execute(['index' => 'site', '--source' => 'article'], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString('Reindexing "site" (source "article")', $tester->getDisplay());
        self::assertStringContainsString('source "article": 2 documents', $tester->getDisplay());
        self::assertStringNotContainsString('source "product"', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['index' => 'site'], ['interactive' => false]));
        self::assertStringContainsString('source "product": 3 documents', $tester->getDisplay());
        self::assertStringContainsString('source "legacy": 2 documents', $tester->getDisplay());

        $tester->execute(['index' => 'site', '--source' => 'nothing'], ['interactive' => false]);
        self::assertStringContainsString('has no source "nothing" (its sources: product, article, legacy): skipped', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['index' => 'site', '--source' => 'article', '--in-place' => true], ['interactive' => false]));
    }
}
