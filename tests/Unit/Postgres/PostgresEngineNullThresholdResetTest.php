<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Inside the search transaction a statement that sets nothing must see the session's own pg_trgm.word_similarity_threshold, as it
 * did when every statement had a transaction of its own: only the statement that compares words with `<%` reads it, so only that one
 * costs the round trip that puts it back (PostgresEngine::settle()).
 *
 * The case: the typo-tolerant answer found nothing, the relaxation dropped a word and ran a strict statement (which sets nothing, so
 * the search's setting is now null) that found nothing either: the typo-tolerant answer stands, and the facets are counted over its
 * candidates by statements that run with that null setting. A real database does not get here by itself (a word that is kept keeps
 * the relaxed search typo-tolerant), so the answers are scripted.
 */
final class PostgresEngineNullThresholdResetTest extends TestCase
{
    private const string RESET = "SELECT set_config('pg_trgm.word_similarity_threshold', :v, true)";

    /**
     * @param int $probed how many documents each typo-tolerant word matches alone (more than the candidate limit of 10: the
     *                    word keeps its `<%` condition in the ranked statement and the facets)
     */
    private function search(int $probed): ScriptedConnection
    {
        $connection = new ScriptedConnection(
            rows: static function (string $sql) use ($probed): ?array {
                return match (true) {
                    str_starts_with($sql, 'SELECT s.id') => array_fill(0, $probed, ['id' => 1]),
                    // the relaxation probe: "mouze" matches nothing on its own, "ab" and "cd" do (but not together)
                    str_contains($sql, 'm0 AS MATERIALIZED') => [['l0' => false, 'l1' => true, 'l2' => true]],
                    str_contains($sql, 'count(*) AS n') => [['value' => true, 'n' => 1]],
                    default => null,
                };
            },
            // the value the session had: a threshold of its own, which the search's setting replaced for the transaction
            value: static fn(string $sql): ?string => str_starts_with($sql, 'WITH old AS') ? '0.55' : null,
        );

        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));
        // "ab" and "cd" are shorter than typo tolerance needs: the relaxed query has no typo-tolerant word and runs strict
        $result = $search->in('products')->query('mouze ab cd')->thresholds(['did_you_mean' => false, 'candidate_limit' => 10])->facets('in_stock')->get();

        self::assertSame(0, $result->total);
        self::assertSame([], $result->warnings, 'the relaxed search found nothing: the original answer stands, nothing was relaxed');

        return $connection;
    }

    public function testAFacetThatComparesWordsGetsTheSessionsValueBack(): void
    {
        $connection = $this->search(11);

        // the probes of a very common word set the similarity they look at (more round trips of the same statement): only the session's own value counts
        $resets = array_keys(array_filter($connection->log, static fn(string $sql, int $i): bool => $sql === self::RESET && $connection->params[$i] === ['v' => '0.55'], ARRAY_FILTER_USE_BOTH));
        self::assertCount(1, $resets, 'the value is put back once');
        $reset = $resets[0];
        self::assertSame(['v' => '0.55'], $connection->params[$reset], "the session's own value, as read when the transaction first set it");
        // right before the facet statement, which is the one that reads `<%`, after the relaxed search that set nothing
        self::assertStringContainsString('count(*) AS n', $connection->log[$reset + 1]);
        self::assertStringContainsString('<%', $connection->log[$reset + 1]);
        self::assertStringNotContainsString('<%', $connection->log[$reset - 1], 'the relaxed strict statement before it');
        self::assertSame('COMMIT', $connection->log[$reset + 2]);
        self::assertSame(1, count(array_keys($connection->kinds(), 'BEGIN', true)));
    }

    public function testAFacetOverProbedCandidatesNeedsNoRoundTripForIt(): void
    {
        // each word matches few documents: the ranked statement and the facets hold their ids, not `<%`: nothing reads the setting
        $connection = $this->search(2);

        self::assertNotContains(self::RESET, $connection->log);
        $facet = array_values(array_filter($connection->log, static fn(string $sql): bool => str_contains($sql, 'count(*) AS n')));
        self::assertCount(1, $facet);
        self::assertStringNotContainsString('<%', $facet[0]);
    }
}
