<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Connection as Dbal;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Logging\Middleware;
use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Fuzzphony\Tests\Integration\ResultSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The search transaction (PostgresEngine::inSearchTransaction()) over the application's Doctrine DBAL connection, whose
 * transaction nesting levels and optional savepoints a PDO connection does not have: the same searches as over a PdoConnection
 * must give the same answers, with exactly one BEGIN / COMMIT around the typo-tolerant statements when Fuzzphony owns the
 * transaction, with none (and no savepoint) of its own when the application's transaction is open, and nothing left behind
 * (an open transaction, a nesting level, a pg_trgm setting) when a statement fails.
 */
final class DbalSearchTransactionTest extends TestCase
{
    private const string WORD_SIMILARITY = 'pg_trgm.word_similarity_threshold';
    private const string SIMILARITY = 'pg_trgm.similarity_threshold';

    private Connection $pdo;
    private Dbal $dbal;
    private StatementLog $log;
    private Fuzzphony $viaPdo;
    private Fuzzphony $viaDbal;

    protected function setUp(): void
    {
        $this->pdo = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($this->pdo, EngineConformanceTestCase::fixtureRows());
        $registry = new IndexRegistry([Indexes::products('manual')]);
        $setup = new Fuzzphony(new PostgresEngine($this->pdo), $registry);
        $setup->schema()->apply($this->pdo);
        $setup->reindex('products');

        $this->log = new StatementLog();
        $this->dbal = DoctrineTestCase::dbalConnection([new Middleware($this->log)]);
        $this->viaPdo = $setup;
        $this->viaDbal = new Fuzzphony(new PostgresEngine(new DbalConnection($this->dbal)), $registry);
    }

    protected function tearDown(): void
    {
        // a failed test must not leave a transaction (and its locks) in the way of the next test's DROP TABLE
        while ($this->dbal->isTransactionActive()) {
            $this->dbal->rollBack();
        }
        $this->dbal->close();
    }

    /** @return array<string, array{\Closure(Fuzzphony): SearchBuilder}> typo-tolerant searches */
    public static function typoSearches(): array
    {
        return [
            'fallback' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('mouze')],
            'always' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('wireles mouse')->thresholds(['fuzzy_mode' => 'always'])],
            'relaxed when empty' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('wireless mouse offfice')],
            'facets' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('mouze')->facets('brand_id', 'in_stock')],
            'one facet' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('mouze')->where('in_stock', true)->facets('brand_id')],
            'did you mean' => [static fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query('hedphones')],
        ];
    }

    /** @param \Closure(Fuzzphony): SearchBuilder $search */
    #[DataProvider('typoSearches')]
    public function testWithoutACallersTransactionTheTypoStatementsShareOneTransactionAndTheAnswerIsThePdoAnswer(\Closure $search): void
    {
        $this->setSessionThresholds();
        $expected = ResultSnapshot::of($search($this->viaPdo)->get());
        $this->log->reset();

        $actual = ResultSnapshot::of($search($this->viaDbal)->get());

        self::assertSame($expected, $actual);
        self::assertNotSame([], $actual['hits'], 'a search that found something');
        self::assertSame(['BEGIN', 'COMMIT'], $this->log->boundaries(), 'one transaction, nothing nested, no savepoint, no rollback');
        $begin = array_search('BEGIN', $this->log->events, true);
        $commit = array_search('COMMIT', $this->log->events, true);
        self::assertIsInt($begin);
        self::assertIsInt($commit);
        $typo = [...$this->log->positions('<%'), ...$this->log->positions('set_config'), ...$this->log->positions('count(*) AS n'), ...$this->log->positions('LEFT JOIN LATERAL')];
        self::assertNotSame([], $this->log->positions('<%'), 'the typo-tolerant statements ran');
        foreach ($typo as $position) {
            self::assertGreaterThan($begin, $position, $this->log->events[$position]);
            self::assertLessThan($commit, $position, $this->log->events[$position]);
        }
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
        self::assertFalse($this->dbal->isTransactionActive());
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds(), 'the transaction-local settings ended with the COMMIT');
    }

    public function testAPlainSearchHasNoTransactionAtAll(): void
    {
        $this->setSessionThresholds();
        $this->log->reset();

        $result = $this->viaDbal->in('products')->query('mouse')->thresholds(['fallback_below' => 1, 'did_you_mean' => false])->facets('brand_id')->get();

        self::assertEqualsCanonicalizing([1, 2, 4], $result->ids());
        self::assertFalse($result->usedFuzzy);
        self::assertSame([], $this->log->boundaries());
        self::assertSame([], $this->log->positions('set_config'));
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
    }

    /** @param \Closure(Fuzzphony): SearchBuilder $search */
    #[DataProvider('typoSearches')]
    public function testInTheCallersTransactionFuzzphonyOpensNoTransactionAndLeavesTheSettingsAsTheyWere(\Closure $search): void
    {
        $this->setSessionThresholds();
        $expected = ResultSnapshot::of($search($this->viaPdo)->get());
        $this->dbal->beginTransaction();
        $this->log->reset();

        $actual = ResultSnapshot::of($search($this->viaDbal)->get());

        self::assertSame($expected, $actual);
        self::assertNotSame([], $this->log->positions('set_config'), 'the per-statement path: every statement sets its setting and puts the previous value back');
        $this->assertOnlyBalancedSavepoints(2);
        self::assertSame(1, $this->dbal->getTransactionNestingLevel(), "the caller's transaction, and only it");
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds(), "put back, inside the caller's transaction (it would otherwise stay on until the caller commits)");

        $this->dbal->commit();
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds());
    }

    /** @param \Closure(Fuzzphony): SearchBuilder $search */
    #[DataProvider('typoSearches')]
    public function testTheCallerCanRollBackToItsOwnSavepointsAfterTheSearch(\Closure $search): void
    {
        $this->setSessionThresholds();
        $expected = ResultSnapshot::of($search($this->viaPdo)->get());
        $this->dbal->beginTransaction();
        $this->dbal->beginTransaction(); // the caller's nested transaction: DOCTRINE_2
        $this->dbal->createSavepoint('caller_sp');
        $this->log->reset();

        $actual = ResultSnapshot::of($search($this->viaDbal)->get());

        self::assertSame($expected, $actual);
        $this->assertOnlyBalancedSavepoints(3);
        self::assertSame(2, $this->dbal->getTransactionNestingLevel());
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds());

        $this->dbal->rollbackSavepoint('caller_sp');
        $this->dbal->rollBack(); // to DOCTRINE_2: still there
        self::assertSame(1, $this->dbal->getTransactionNestingLevel());
        self::assertSame(1, $this->dbal->fetchOne('SELECT 1'), "the caller's transaction is alive");
        $this->dbal->commit();
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
    }

    /** @return array<string, array{string, string, string}> the column that is renamed, the search that needs it, the statement it fails in */
    public static function failures(): array
    {
        return [
            // the typo-tolerant branch is the first thing this search does: the probe of the word fails, after the setting was made
            'the probe' => ['fz', 'mouze', 'always'],
            // the ranked statement is fine; the facet, the last statement of the transaction, fails
            'the facet' => ['f_in_stock', 'mouze', 'fallback'],
        ];
    }

    #[DataProvider('failures')]
    public function testAFailingStatementOfTheSearchTransactionRollsItBackAndTheConnectionIsUsable(string $column, string $text, string $mode): void
    {
        $this->setSessionThresholds();
        $search = fn(Fuzzphony $f): SearchBuilder => $f->in('products')->query($text)->thresholds(['fuzzy_mode' => $mode])->facets('in_stock');
        $expected = ResultSnapshot::of($search($this->viaPdo)->get());
        $this->rename($column, $column . '_gone');
        $this->log->reset();

        try {
            $search($this->viaDbal)->get();
            self::fail('the statement fails');
        } catch (EngineFailure) {
        }

        $boundaries = $this->log->boundaries();
        self::assertSame(['BEGIN', 'ROLLBACK'], $boundaries, 'rolled back, not committed, nothing nested');
        self::assertNotSame([], $this->log->positions('set_config'), 'the failure came after the setting was made');
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
        self::assertFalse($this->dbal->isTransactionActive());
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds());

        // not an aborted transaction left over: the very same connection and engine search again
        $this->rename($column . '_gone', $column);
        self::assertSame($expected, ResultSnapshot::of($search($this->viaDbal)->get()));
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
    }

    public function testAFailingTypoStatementInTheCallersTransactionRollsBackToItsSavepointOnly(): void
    {
        $this->setSessionThresholds();
        $expected = ResultSnapshot::of($this->viaPdo->in('products')->query('mouze')->get());
        $this->rename('fz', 'fz_gone');
        $this->dbal->beginTransaction();
        $this->log->reset();

        try {
            $this->viaDbal->in('products')->query('mouze')->thresholds(['fuzzy_mode' => 'always'])->get();
            self::fail('the statement fails');
        } catch (EngineFailure) {
        }

        // The statement that sets a setting runs in the savepoint DBAL nests it in: that is rolled back, with its setting, and
        // the caller's transaction is not aborted. (DBAL >= 4.2 always nests with savepoints; the fallback path is the one that
        // was always taken inside a caller's transaction.)
        self::assertSame(['SAVEPOINT DOCTRINE_2', 'ROLLBACK TO SAVEPOINT DOCTRINE_2'], $this->log->boundaries());
        self::assertSame(1, $this->dbal->getTransactionNestingLevel());
        self::assertFalse($this->dbal->isRollbackOnly());
        self::assertSame(1, $this->dbal->fetchOne('SELECT 1'));
        self::assertSame(['0.55', '0.22'], $this->sessionThresholds());
        $this->dbal->commit();
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());

        $this->rename('fz_gone', 'fz');
        self::assertSame($expected, ResultSnapshot::of($this->viaDbal->in('products')->query('mouze')->get()));
    }

    public function testAFailingStrictStatementInTheCallersTransactionAbortsItAsItAlwaysDid(): void
    {
        $this->setSessionThresholds();
        $expected = ResultSnapshot::of($this->viaPdo->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->get());
        $this->rename('tsv', 'tsv_gone');
        $this->dbal->beginTransaction();

        try {
            $this->viaDbal->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->get();
            self::fail('the statement fails');
        } catch (EngineFailure) {
        }

        // The strict statement sets nothing, so it has no transaction (no savepoint) of its own: PostgreSQL aborts the caller's
        // transaction, as it does for any failing statement of the application, and accepts nothing but ROLLBACK from here on.
        self::assertSame(1, $this->dbal->getTransactionNestingLevel());
        try {
            $this->dbal->fetchOne('SELECT 1');
            self::fail("the caller's transaction is aborted");
        } catch (DbalException) {
        }

        $this->dbal->rollBack();
        self::assertSame(0, $this->dbal->getTransactionNestingLevel());
        $this->rename('tsv_gone', 'tsv');
        self::assertSame($expected, ResultSnapshot::of($this->viaDbal->in('products')->query('mouse')->thresholds(['fuzzy_mode' => 'never'])->get()), 'the connection works after the rollback');
    }

    /** The only things Fuzzphony does about transactions inside the caller's: savepoints DBAL nests the per-statement transactions in, all released. */
    private function assertOnlyBalancedSavepoints(int $level): void
    {
        $boundaries = $this->log->boundaries();
        $opened = array_values(array_filter($boundaries, static fn(string $e): bool => str_starts_with($e, 'SAVEPOINT ')));
        $released = array_values(array_filter($boundaries, static fn(string $e): bool => str_starts_with($e, 'RELEASE SAVEPOINT ')));
        self::assertCount(count($boundaries), [...$opened, ...$released], 'no BEGIN, COMMIT, ROLLBACK or ROLLBACK TO');
        self::assertNotSame([], $opened);
        self::assertSame(array_fill(0, count($opened), 'SAVEPOINT DOCTRINE_' . $level), $opened, "one at a time, one level above the caller's");
        self::assertSame(array_fill(0, count($opened), 'RELEASE SAVEPOINT DOCTRINE_' . $level), $released, 'each released');
    }

    private function rename(string $from, string $to): void
    {
        $this->pdo->execute(sprintf('ALTER TABLE "fuzzphony_products" RENAME COLUMN "%s" TO "%s"', $from, $to));
    }

    /** The application's own values, which the transaction-local settings of a search must not outlast. */
    private function setSessionThresholds(): void
    {
        $this->dbal->fetchOne(sprintf("SELECT set_config('%s', '0.55', false), set_config('%s', '0.22', false)", self::WORD_SIMILARITY, self::SIMILARITY));
    }

    /** @return list<mixed> */
    private function sessionThresholds(): array
    {
        return [
            $this->dbal->fetchOne(sprintf("SELECT current_setting('%s', true)", self::WORD_SIMILARITY)),
            $this->dbal->fetchOne(sprintf("SELECT current_setting('%s', true)", self::SIMILARITY)),
        ];
    }
}
