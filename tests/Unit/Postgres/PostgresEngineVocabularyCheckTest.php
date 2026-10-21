<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The did-you-mean lookup asks once per process whether the vocabulary table is readable; only a positive answer is kept. */
final class PostgresEngineVocabularyCheckTest extends TestCase
{
    public function testAPositiveAnswerIsKeptForTheLifeOfTheEngine(): void
    {
        $connection = new ScriptedConnection(value: static fn(): bool => true);
        $search = self::search($connection);

        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('keybord')->get();

        self::assertSame(1, self::times($connection, 'vocabulary check'));
        self::assertSame(3, self::times($connection, 'did you mean'), 'every search still looks the words up');
    }

    public function testANegativeAnswerIsNeverKept(): void
    {
        $answers = [false, false, true, true];
        $connection = new ScriptedConnection(value: static function (string $sql) use (&$answers): mixed {
            return str_contains($sql, 'to_regclass') ? (array_shift($answers) ?? true) : null;
        });
        $search = self::search($connection);

        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('mouze')->get();
        self::assertSame(0, self::times($connection, 'did you mean'), 'no readable table, no lookup');
        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('mouze')->get();

        self::assertSame(3, self::times($connection, 'vocabulary check'), 'asked until it said yes, then kept');
        self::assertSame(2, self::times($connection, 'did you mean'));
    }

    public function testACallersTransactionKeepsAskingEverySearch(): void
    {
        // a lookup that fails inside the caller's transaction would abort it: the check is not trusted there
        $connection = new ScriptedConnection(callerTransaction: true, value: static fn(): bool => true);
        $search = self::search($connection);

        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('mouze')->get();

        self::assertSame(2, self::times($connection, 'vocabulary check'));
    }

    public function testAConnectionThatCannotTellKeepsAskingEverySearch(): void
    {
        $connection = new RecordingConnection(static fn(): bool => true);
        $search = self::search($connection);

        $search->in('products')->query('mouze')->get();
        $search->in('products')->query('mouze')->get();

        $checks = array_filter($connection->log, static fn(array $entry): bool => str_contains($entry[0], 'to_regclass'));
        self::assertCount(2, $checks);
    }

    public function testALookupThatFailsForgetsTheAnswerAndFailsLikeAnyLookup(): void
    {
        $failing = new \stdClass();
        $failing->on = false;
        $connection = new ScriptedConnection(
            rows: static function (string $sql) use ($failing): ?array {
                if ($failing->on && str_contains($sql, 'LEFT JOIN LATERAL')) {
                    throw new \RuntimeException('relation "fuzzphony_products__vocab" does not exist');
                }

                return null;
            },
            value: static fn(): bool => true,
        );
        $search = self::search($connection);
        $search->in('products')->query('mouze')->get();
        self::assertSame(1, self::times($connection, 'vocabulary check'));

        $failing->on = true;
        try {
            $search->in('products')->query('mouze')->get();
            self::fail('the lookup failed');
        } catch (EngineFailure $e) {
            self::assertStringContainsString('fuzzphony:reindex --vocabulary', $e->getMessage());
        }
        self::assertSame(1, self::times($connection, 'vocabulary check'), 'the failed search trusted the answer it had');

        $failing->on = false;
        $search->in('products')->query('mouze')->get();
        self::assertSame(2, self::times($connection, 'vocabulary check'), 'and the next one asks again');
    }

    public function testResetCachesForgetsTheAnswer(): void
    {
        $connection = new ScriptedConnection(value: static fn(): bool => true);
        $engine = new PostgresEngine($connection);
        $search = new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouze')->get();
        $engine->resetCaches();
        $search->in('products')->query('mouze')->get();

        self::assertSame(2, self::times($connection, 'vocabulary check'));
    }

    private static function search(\Fuzzphony\Core\Database\Connection $connection): Fuzzphony
    {
        return new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));
    }

    private static function times(ScriptedConnection $connection, string $kind): int
    {
        return count(array_keys($connection->kinds(), $kind, true));
    }
}
