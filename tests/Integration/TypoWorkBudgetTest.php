<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * A restricted viewer's typo branch scores the matches of a very common typo word within a WORK budget: rows, and the stored
 * size of the text the viewer may SEE (the recheck of a document of 17 000 words costs thousands of times a short name's). Short
 * texts keep the row budget (30 000, divided by the words of the query); long ones lower it, never under the candidates asked for.
 * A hidden field never moves it: the candidates must not depend on what a hidden field holds.
 */
final class TypoWorkBudgetTest extends TestCase
{
    private const int MATCHES = 1500;
    private const int LIMIT = 100;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_wb_doc, fuzzphony_wb_docs, fuzzphony_wb_docs__vocab, fuzzphony_wb_docs__next, fuzzphony_wb_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_wb_doc (id bigint PRIMARY KEY, title text NOT NULL, body text NOT NULL)');
    }

    /**
     * @param string $bodySql the hidden field, an expression of the row number `i`
     * @param array<string, string> $params
     *
     * @return array{Fuzzphony, int} the facade and the number of documents the scan of the typo selection was allowed to read
     */
    private function scan(string $bodySql, array $params, string $titleSql = "'common filler n' || i"): array
    {
        $this->connection->execute(sprintf("INSERT INTO fz_wb_doc SELECT i, %s, %s FROM generate_series(1, %d) AS i", $titleSql, $bodySql, self::MATCHES), $params);
        $index = IndexDefinition::builder('wb_docs')->fromQuery('SELECT id, title, body FROM fz_wb_doc')
            ->field('body', 'A', fuzzy: true)->field('title', 'B', fuzzy: true)->language('english')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$index]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('wb_docs');
        $this->connection->execute('ANALYZE fuzzphony_wb_docs');

        $explained = $fuzzphony->in('wb_docs')->query('comon')->thresholds(['fuzzy_mode' => 'always', 'candidate_limit' => self::LIMIT])->restrictedToFields('title')->limit(5)->explain();
        self::assertTrue($explained->result->totalIsLowerBound);
        $statement = array_values(array_filter($explained->statements, static fn(array $s): bool => $s['label'] === 'full-text + fuzzy'))[0] ?? self::fail('no typo statement');
        // the scan limit is an expression of the first matches' stored size: evaluate it over the same CTEs
        self::assertSame(1, preg_match('/LIMIT (greatest\(.*?::bigint\)\))\n/', $statement['sql'], $limit));
        $head = substr($statement['sql'], 0, (int) strpos($statement['sql'], "fuzzy AS (\n"));
        $rows = $this->connection->fetchAll(rtrim($head, ",\n") . "\nSELECT " . ($limit[1] ?? self::fail('no scan limit')) . ' AS n', $statement['params']);

        return [$fuzzphony, Coerce::int($rows[0]['n'] ?? null)];
    }

    public function testShortTextsKeepTheRowBudget(): void
    {
        [, $scan] = $this->scan("'about things ' || i", []);

        self::assertGreaterThanOrEqual(self::MATCHES, $scan, 'every one of the matches is scored');
    }

    public function testLongVisibleTextsLowerTheScanButNeverUnderTheCandidates(): void
    {
        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        [$fuzzphony, $scan] = $this->scan("'about things ' || i", ['long' => $long], "CASE WHEN i % 10 = 0 THEN 'common ' || :long ELSE 'common filler n' || i END");

        self::assertLessThan(self::MATCHES, $scan, 'a tenth of the visible titles hold 17 000 words: the scan stops before the end');
        self::assertGreaterThanOrEqual(self::LIMIT, $scan);
        // the answer is still a page of the viewer's own matches, found within the bound
        $result = $fuzzphony->in('wb_docs')->query('comon')->thresholds(['fuzzy_mode' => 'always', 'candidate_limit' => self::LIMIT])->restrictedToFields('title')->limit(5)->get();
        self::assertCount(5, $result->hits);
        self::assertTrue($result->totalIsLowerBound);
    }

    /** What a hidden field holds, however long, does not move the budget: the candidates cannot depend on it. */
    public function testALongHiddenTextDoesNotMoveTheScan(): void
    {
        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        [, $scan] = $this->scan("CASE WHEN i % 10 = 0 THEN :long ELSE '' END", ['long' => $long]);

        self::assertGreaterThanOrEqual(self::MATCHES, $scan, 'the same scan as with short hidden texts');
    }
}
