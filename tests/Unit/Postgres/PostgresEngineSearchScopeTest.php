<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** One search, one transaction: the typo-tolerant statements, the relaxation, the facets and the did-you-mean lookup share it. */
final class PostgresEngineSearchScopeTest extends TestCase
{
    public function testATypoSearchWithDidYouMeanIsOneTransaction(): void
    {
        $connection = new ScriptedConnection(value: static fn(): bool => true);
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouze')->get();

        // the strict statement needs no setting: it runs before the transaction, which starts with the typo-tolerant phase;
        // the probe and the ranked statement want the same similarity, so it is set once
        self::assertSame(
            ['statement', 'stop words', 'BEGIN', 'set_config', 'statement', 'statement', 'vocabulary check', 'set_config', 'did you mean', 'COMMIT'],
            $connection->kinds(),
        );
    }

    public function testAPlainSearchHasNoTransaction(): void
    {
        $connection = new ScriptedConnection(rows: static fn(string $sql): ?array => str_starts_with($sql, 'WITH q AS') ? array_fill(0, 6, [
            'total' => 6, 'fts_n' => 6, 'fuzzy_n' => 0, 'id' => '1', 'score' => 1.0, 'r_text' => 1.0, 'r_fuzzy' => 0.0,
            'relevance' => 1.0, 'exact_bonus' => 0.0, 'prefix_bonus' => 0.0, 'boost_bonus' => 0.0, 'recency_bonus' => 0.0,
        ]) : null);
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouse')->thresholds(['did_you_mean' => false])->get();

        self::assertNotContains('BEGIN', $connection->kinds());
        self::assertNotContains('set_config', $connection->kinds());
    }

    public function testACallersTransactionKeepsEveryStatementOnItsOwn(): void
    {
        $connection = new ScriptedConnection(callerTransaction: true, value: static fn(): bool => true);
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouze')->get();

        self::assertNotContains('BEGIN', $connection->kinds(), 'the caller owns the transaction');
        // every setting is read and set, and put back (two statements each)
        self::assertSame(6, count(array_keys($connection->kinds(), 'set_config', true)));
    }

    public function testAFailingStatementRollsTheSearchBackAndIsTheSameFailure(): void
    {
        $connection = new ScriptedConnection(
            rows: static function (string $sql): ?array {
                if (str_contains($sql, 'LEFT JOIN LATERAL')) {
                    throw new \RuntimeException('boom');
                }

                return null;
            },
            value: static fn(): bool => true,
        );
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        try {
            $search->in('products')->query('mouze')->get();
            self::fail('the lookup failed');
        } catch (EngineFailure) {
            self::assertSame('ROLLBACK', array_last($connection->kinds()));
        }
        // and the engine is usable again: it holds no transaction state
        $connection->log = [];
        try {
            $search->in('products')->query('mouze')->get();
        } catch (EngineFailure) {
        }
        self::assertSame('BEGIN', $connection->kinds()[1], 'the stop words are known now: the strict statement, then the transaction');
    }
}
