<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * A search inside the caller's transaction on a PdoConnection behaves as on DBAL (DbalSearchTransactionTest): one savepoint around the
 * statements that set a setting, the previous values put back once, and a failing search rolls back to the savepoint, so the
 * caller's transaction stays usable.
 */
final class PdoCallerTransactionTest extends TestCase
{
    private const string WORD_SIMILARITY = 'pg_trgm.word_similarity_threshold';
    private const string SIMILARITY = 'pg_trgm.similarity_threshold';

    private \PDO $pdo;
    private PdoConnection $plain;
    private InterceptingConnection $spy;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $setup = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($setup, EngineConformanceTestCase::fixtureRows());
        $registry = new IndexRegistry([Indexes::products('manual')]);
        $seed = new Fuzzphony(new PostgresEngine($setup), $registry);
        $seed->schema()->apply($setup);
        $seed->reindex('products');

        $this->pdo = new \PDO(PostgresTestCase::dsn());
        $this->plain = new PdoConnection($this->pdo);
        $this->spy = new InterceptingConnection($this->plain);
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->spy), $registry);
        $this->pdo->exec(sprintf("SELECT set_config('%s', '0.55', false), set_config('%s', '0.22', false)", self::WORD_SIMILARITY, self::SIMILARITY));
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return list<string> the transaction boundaries Fuzzphony sent */
    private function boundaries(): array
    {
        $found = [];
        foreach ($this->spy->log as [$sql]) {
            if (preg_match('/^(BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/', $sql) === 1) {
                $found[] = $sql;
            }
        }

        return $found;
    }

    /** @return list<mixed> */
    private function thresholds(): array
    {
        return [
            $this->plain->fetchValue(sprintf("SELECT current_setting('%s', true)", self::WORD_SIMILARITY)),
            $this->plain->fetchValue(sprintf("SELECT current_setting('%s', true)", self::SIMILARITY)),
        ];
    }

    public function testASearchInTheCallersTransactionHoldsOneSavepointAndLeavesTheSettingsAsTheyWere(): void
    {
        $expected = ResultSnapshot::of($this->fuzzphony->in('products')->query('mouze')->get());
        $this->pdo->beginTransaction();
        $this->spy->log = [];

        $actual = ResultSnapshot::of($this->fuzzphony->in('products')->query('mouze')->get());

        self::assertSame($expected, $actual);
        self::assertSame(['SAVEPOINT fuzzphony_search', 'RELEASE SAVEPOINT fuzzphony_search'], $this->boundaries());
        self::assertTrue($this->pdo->inTransaction(), "the caller's transaction, and only it");
        self::assertSame(['0.55', '0.22'], $this->thresholds(), "put back, inside the caller's transaction");
        $this->pdo->commit();
        self::assertSame(['0.55', '0.22'], $this->thresholds());
    }

    public function testAFailingTypoStatementLeavesTheCallersTransactionUsable(): void
    {
        $expected = ResultSnapshot::of($this->fuzzphony->in('products')->query('mouze')->get());
        PostgresTestCase::connect()->execute('ALTER TABLE "fuzzphony_products" RENAME COLUMN "fz" TO "fz_gone"');
        try {
            $this->pdo->beginTransaction();
            $this->spy->log = [];

            try {
                $this->fuzzphony->in('products')->query('mouze')->thresholds(['fuzzy_mode' => 'always'])->get();
                self::fail('the statement fails');
            } catch (EngineFailure) {
            }

            self::assertSame(['SAVEPOINT fuzzphony_search', 'ROLLBACK TO SAVEPOINT fuzzphony_search', 'RELEASE SAVEPOINT fuzzphony_search'], $this->boundaries());
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(1, $this->plain->fetchValue('SELECT 1'), "the caller's transaction is not aborted");
            self::assertSame(['0.55', '0.22'], $this->thresholds());
            $this->pdo->commit();
        } finally {
            PostgresTestCase::connect()->execute('ALTER TABLE "fuzzphony_products" RENAME COLUMN "fz_gone" TO "fz"');
        }
        self::assertSame($expected, ResultSnapshot::of($this->fuzzphony->in('products')->query('mouze')->get()));
    }

    public function testAPlainSearchInTheCallersTransactionOpensNothing(): void
    {
        $this->pdo->beginTransaction();
        $this->spy->log = [];

        $this->fuzzphony->in('products')->query('mouse')->thresholds(['fallback_below' => 1, 'did_you_mean' => false])->get();

        self::assertSame([], $this->boundaries(), 'no setting, no savepoint');
    }
}
