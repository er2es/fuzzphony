<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * The sidecar column `tsv_capped` (true when the joined vector `tsv` reached position 16 383): the later fields' vectors are read
 * for a phrase or a scoped word only for a document that is capped, or not known to be (NULL); an install without the column works
 * the slower way. Index order: notes (long in the capped document), title, body, secret.
 */
final class CappedFlagTest extends TestCase
{
    private const array QUERIES = ['"word1 word2"', 'secret:word1', 'title:"word1 word2"', 'word1-word2', 'word1 -"word1 word2"', '"ends word1"'];

    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_cap_doc, fuzzphony_cap_docs, fuzzphony_cap_docs__vocab, fuzzphony_cap_docs__next, fuzzphony_cap_docs__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_cap_doc (id bigint PRIMARY KEY, notes text NOT NULL, title text NOT NULL, body text NOT NULL, secret text NOT NULL)');
        $filler = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        // 1 and 3 have the long field, 2 and 4 a short one; 3 and 4 hold the words in other fields
        foreach ([[1, $filler, 'word1 word2', 'plain', 'word1'], [2, 'x', 'word1 word2', 'plain', 'word1'], [3, $filler, 'plain', 'ends word1', 'word2 start'], [4, 'x', 'plain', 'ends word1', 'word2 start']] as [$id, $notes, $title, $body, $secret]) {
            $this->connection->execute('INSERT INTO fz_cap_doc VALUES (:id, :notes, :title, :body, :secret)', ['id' => $id, 'notes' => $notes, 'title' => $title, 'body' => $body, 'secret' => $secret]);
        }
        $this->fuzzphony = $this->engine();
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('cap_docs');
    }

    /** A fresh engine: it asks again whether the column is there. */
    private function engine(): Fuzzphony
    {
        $builder = IndexDefinition::builder('cap_docs')->fromQuery('SELECT id, notes, title, body, secret FROM fz_cap_doc');
        foreach (['notes' => 'A', 'title' => 'B', 'body' => 'C', 'secret' => 'D'] as $name => $weight) {
            $builder = $builder->field($name, $weight, fuzzy: false);
        }

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$builder->language('english')->sync('manual')->build()]));
    }

    /** @return array<string, list<int|string>> the ids each query finds, in all three fuzzy modes the same */
    private function found(Fuzzphony $fuzzphony): array
    {
        $found = [];
        foreach (self::QUERIES as $text) {
            $ids = function (string $mode) use ($fuzzphony, $text): array {
                $hits = array_map(static fn($hit): int|string => $hit->id, $fuzzphony->in('cap_docs')->query($text)->thresholds(['fuzzy_mode' => $mode])->get()->hits);
                sort($hits);

                return $hits;
            };
            $never = $ids('never');
            self::assertSame($never, $ids('fallback'), $text . ' fallback');
            self::assertSame($never, $ids('always'), $text . ' always');
            $found[$text] = $never;
        }

        return $found;
    }

    /** @return array<int, bool|null> */
    private function flags(): array
    {
        $flags = [];
        foreach ($this->connection->fetchAll('SELECT id, tsv_capped FROM fuzzphony_cap_docs ORDER BY id') as $row) {
            $flags[Coerce::int($row['id'])] = $row['tsv_capped'] === null ? null : ($row['tsv_capped'] === true || $row['tsv_capped'] === 't' || $row['tsv_capped'] === '1');
        }

        return $flags;
    }

    /** What every document that holds the words must give, with the flag right, NULL or the column missing. */
    private const array EXPECTED = [
        '"word1 word2"' => [1, 2, 3, 4],
        'secret:word1' => [1, 2],
        'title:"word1 word2"' => [1, 2],
        'word1-word2' => [1, 2, 3, 4],
        'word1 -"word1 word2"' => [],
        '"ends word1"' => [3, 4],
    ];

    public function testTheReindexSetsTheFlagFromTheJoinedVector(): void
    {
        self::assertSame([1 => true, 2 => false, 3 => true, 4 => false], $this->flags());
        self::assertSame(self::EXPECTED, $this->found($this->fuzzphony));
    }

    public function testARefreshOfOneDocumentSetsItsFlag(): void
    {
        $this->connection->execute("UPDATE fz_cap_doc SET notes = 'short' WHERE id = 1");
        $this->connection->execute("UPDATE fz_cap_doc SET notes = (SELECT string_agg('g' || g, ' ') FROM generate_series(1, 16400) g) WHERE id = 4");
        $this->fuzzphony->refresh('cap_docs', [1, 4]);

        self::assertSame([1 => false, 2 => false, 3 => true, 4 => true], $this->flags());
    }

    public function testAnUnknownFlagIsTreatedAsCapped(): void
    {
        $this->connection->execute('UPDATE fuzzphony_cap_docs SET tsv_capped = NULL');

        self::assertSame([1 => null, 2 => null, 3 => null, 4 => null], $this->flags());
        self::assertSame(self::EXPECTED, $this->found($this->engine()));
    }

    public function testTheFlagDecidesWhetherTheLaterFieldsAreRead(): void
    {
        // a wrong "false" on a capped document hides what only the later fields' vectors show: the flag is the contract
        $this->connection->execute('UPDATE fuzzphony_cap_docs SET tsv_capped = false');
        $found = $this->found($this->engine());

        self::assertSame([2, 4], $found['"word1 word2"'] ?? null, 'title phrase of the capped documents 1 and 3 is not looked for');
        self::assertSame([1, 2], $found['secret:word1'], 'a scoped word needs no flag: the field decides');
    }

    public function testWithoutTheColumnItWorksTheSlowerWay(): void
    {
        $this->connection->execute('ALTER TABLE fuzzphony_cap_docs DROP COLUMN tsv_capped');
        $fuzzphony = $this->engine();

        self::assertSame(self::EXPECTED, $this->found($fuzzphony));
        $statement = $fuzzphony->in('cap_docs')->query('"word1 word2"')->explain()->statements[0]['sql'];
        self::assertStringNotContainsString('tsv_capped', $statement);
        self::assertStringContainsString('s.tsv @@ q.sft0 OR (s."t_title" || s."t_body" || s."t_secret") @@ q.sft0', (string) preg_replace('/\s+/', ' ', $statement), 'the later fields are read for every document tsv does not match');

        $warnings = array_filter($fuzzphony->inspect('cap_docs')->checks, static fn($check): bool => $check->name === 'Sidecar columns');
        self::assertSame([CheckStatus::Ok, CheckStatus::Warning], array_values(array_map(static fn($check): CheckStatus => $check->status, $warnings)), 'a missing flag is a hint, not schema drift');
        self::assertStringContainsString('tsv_capped is missing', implode(' ', array_map(static fn($check): string => $check->message, $warnings)));
    }

    public function testApplyingTheSchemaAddsTheColumnAndTheReindexFillsIt(): void
    {
        $this->connection->execute('ALTER TABLE fuzzphony_cap_docs DROP COLUMN tsv_capped');
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->schema()->apply($this->connection); // again: the column is there, nothing to add

        self::assertSame([1 => null, 2 => null, 3 => null, 4 => null], $this->flags(), 'documents written before the column have no answer yet');
        self::assertSame(self::EXPECTED, $this->found($this->engine()));
        $this->fuzzphony->reindex('cap_docs');
        self::assertSame([1 => true, 2 => false, 3 => true, 4 => false], $this->flags());
    }

    public function testTheFastStatementReadsTheLaterFieldsOnlyForACappedDocument(): void
    {
        $sql = (string) preg_replace('/\s+/', ' ', $this->fuzzphony->in('cap_docs')->query('"word1 word2"')->thresholds(['fuzzy_mode' => 'never'])->explain()->statements[0]['sql']);

        self::assertStringContainsString('s.tsv @@ q.sft0 OR (s.tsv_capped IS NOT FALSE AND EXISTS (SELECT 1 FROM "public"."fuzzphony_cap_docs" AS c WHERE c.id = s.id AND ((c."t_title" || c."t_body" || c."t_secret") @@ q.sft0 OR ', $sql);
        self::assertStringContainsString('CASE WHEN s.tsv_capped IS NOT FALSE AND NOT (s.tsv @@ q.tsq) THEN (SELECT GREATEST(', $sql);
        // the scanned row's later fields are never named outside the subquery: PostgreSQL would fetch them for every row
        self::assertStringNotContainsString(' s."t_secret"', $sql);
    }

    /** @param list<array{int, string, string}> $rows id, notes, title */
    private function load(array $rows): void
    {
        $this->connection->execute('DELETE FROM fz_cap_doc');
        foreach ($rows as [$id, $notes, $title]) {
            $this->connection->execute('INSERT INTO fz_cap_doc VALUES (:id, :notes, :title, :body, :secret)', ['id' => $id, 'notes' => $notes, 'title' => $title, 'body' => 'plain', 'secret' => 'plain']);
        }
        $this->fuzzphony->reindex('cap_docs');
    }

    /** @return list<int|string> */
    private function ids(Fuzzphony $fuzzphony, string $text, string $mode = 'never'): array
    {
        $ids = array_map(static fn($hit): int|string => $hit->id, $fuzzphony->in('cap_docs')->query($text)->thresholds(['fuzzy_mode' => $mode])->get()->hits);
        sort($ids);

        return $ids;
    }

    public function testALexemeWithTheMostPositionsLosesTheLaterFieldsPositionsWithoutReachingThePositionLimit(): void
    {
        // PostgreSQL keeps at most 256 positions per lexeme; `||` drops the later vector's once the lexeme has them, so a phrase in the
        // title is not on tsv although no position reached 16 383: the flag must be true (the review's case)
        $this->load([[1, str_repeat('foo ', 300), 'foo baz foo bar'], [2, 'x', 'foo bar']]);

        self::assertSame([1 => true, 2 => false], $this->flags());
        foreach (['never', 'fallback', 'always'] as $mode) {
            self::assertSame([1, 2], $this->ids($this->fuzzphony, '"foo bar"', $mode), $mode);
            self::assertSame([1, 2], $this->ids($this->fuzzphony, 'title:"foo bar"', $mode), $mode);
        }
        $this->connection->execute('ALTER TABLE fuzzphony_cap_docs DROP COLUMN tsv_capped');
        self::assertSame([1, 2], $this->ids($this->engine(), '"foo bar"'), 'the same as without the column');
    }

    public function testALexemeJustUnderTheLimitIsNotCapped(): void
    {
        // 253 + 2 = 255 positions of "foo" on tsv: nothing was dropped
        $this->load([[1, str_repeat('foo ', 253), 'foo baz foo bar'], [2, 'x', 'foo bar']]);

        self::assertSame([1 => false, 2 => false], $this->flags());
        self::assertSame([1, 2], $this->ids($this->fuzzphony, '"foo bar"'));
    }

    public function testALexemeRepeatedInTheFirstFieldOnlyIsNotCapped(): void
    {
        $this->load([[1, str_repeat('foo ', 400), 'bar baz'], [2, 'x', 'bar baz']]);

        self::assertSame([1 => false, 2 => false], $this->flags(), 'one vector holds at most 255 positions of a lexeme and nothing is dropped');
        self::assertSame([1, 2], $this->ids($this->fuzzphony, '"bar baz"'));
        self::assertSame([1, 2], $this->ids($this->fuzzphony, 'title:"bar baz"'));
    }

    public function testTheRankOfADocumentTsvDoesNotMatchIsTakenFromTheFields(): void
    {
        $this->load([[1, str_repeat('foo ', 300), 'foo baz foo bar'], [2, 'x', 'foo baz foo bar']]);
        $rank = [];
        foreach ($this->fuzzphony->in('cap_docs')->query('title:"foo bar"')->thresholds(['fuzzy_mode' => 'never'])->get()->hits as $hit) {
            $rank[$hit->id] = round($hit->breakdown->textRank, 6);
        }
        ksort($rank);

        self::assertSame([1, 2], array_keys($rank));
        self::assertGreaterThan(0.0, $rank[1], 'ranked on the title vector: the joined one lost the later positions of the title');
        self::assertSame($rank[2], $rank[1]);
    }
}
