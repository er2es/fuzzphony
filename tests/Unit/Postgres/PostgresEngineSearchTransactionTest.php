<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** A transaction is opened only where there is a session setting to scope to it: a single SELECT needs no BEGIN and COMMIT. */
final class PostgresEngineSearchTransactionTest extends TestCase
{
    public function testAPlainSearchIsOneStatementAndNoTransaction(): void
    {
        $connection = new RecordingConnection(static fn(): null => null);
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never', 'did_you_mean' => false])->get();

        self::assertCount(1, $connection->log);
        self::assertStringStartsWith('WITH q AS', $connection->log[0][0]);
    }

    public function testASearchWithASimilarityThresholdKeepsItsTransaction(): void
    {
        $connection = new RecordingConnection(static fn(): null => null);
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products()]));

        $search->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'always', 'did_you_mean' => false])->get();

        $sequence = array_map(
            static fn(array $entry): string => match (true) {
                $entry[0] === 'BEGIN', $entry[0] === 'COMMIT' => $entry[0],
                str_contains($entry[0], 'set_config') => 'set_config',
                default => 'statement',
            },
            $connection->log,
        );
        $transactions = [];
        $open = false;
        foreach ($sequence as $step) {
            if ($step === 'BEGIN') {
                $open = true;
            } elseif ($step === 'COMMIT') {
                $open = false;
            } elseif ($step === 'set_config') {
                $transactions[] = $open;
            }
        }
        self::assertNotSame([], $transactions, 'the similarity threshold is set');
        self::assertNotContains(false, $transactions, 'and only ever inside a transaction');
        self::assertSame(array_count_values($sequence)['BEGIN'], array_count_values($sequence)['COMMIT']);
    }
}
