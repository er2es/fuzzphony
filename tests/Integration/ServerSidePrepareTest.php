<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzphony's statements (PdoConnection) are sent in one call, without PDO's server-side prepare (Parse + Bind/Execute +
 * DEALLOCATE), and the application's own statements on the same PDO are not touched: the option is per statement. A statement that is prepared on the server shows up in
 * pg_prepared_statements while it runs; one that is not, does not.
 */
final class ServerSidePrepareTest extends TestCase
{
    private const string PREPARED = 'SELECT count(*) FROM pg_prepared_statements';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO(PostgresTestCase::dsn(), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function testThePlainPdoPreparesOnTheServerAsTheControl(): void
    {
        $statement = $this->pdo->prepare(self::PREPARED);
        $statement->execute();

        self::assertSame(1, $statement->fetchColumn());
    }

    public function testAPdoConnectionStatementIsNotPreparedOnTheServer(): void
    {
        $connection = new PdoConnection($this->pdo);

        self::assertSame(0, $connection->fetchValue(self::PREPARED));
        self::assertSame([['count' => 0]], $connection->fetchAll(self::PREPARED));
    }

    public function testThePdoKeepsItsAttributeAndTheApplicationsStatementsStillPrepare(): void
    {
        $connection = new PdoConnection($this->pdo);
        $connection->fetchValue(self::PREPARED);

        self::assertFalse($this->pdo->getAttribute(\Pdo\Pgsql::ATTR_DISABLE_PREPARES));
        $statement = $this->pdo->prepare(self::PREPARED);
        $statement->execute();
        self::assertSame(1, $statement->fetchColumn());
    }

    public function testAStatementOfADbalConnectionIsNotPreparedOnTheServerEither(): void
    {
        // DBAL's own pdo_pgsql driver switches the attribute on by default; the bridge adds nothing to it and takes nothing away
        $connection = new DbalConnection(DoctrineTestCase::dbalConnection());

        self::assertSame(0, $connection->fetchValue(self::PREPARED));
    }

    public function testASearchLeavesTheAttributeOfTheSharedPdoAsItWas(): void
    {
        $connection = new PdoConnection($this->pdo);
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $search = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual')]));
        $search->schema()->apply($connection);
        $search->reindex('products');

        $before = $this->pdo->getAttribute(\Pdo\Pgsql::ATTR_DISABLE_PREPARES);
        $search->in('products')->query('mouse')->get();
        $search->in('products')->query('mose')->get();

        self::assertSame($before, $this->pdo->getAttribute(\Pdo\Pgsql::ATTR_DISABLE_PREPARES));
        self::assertFalse($before);
    }

    public function testASearchThroughDbalGivesTheSameHits(): void
    {
        $plain = new PdoConnection($this->pdo);
        PostgresTestCase::createFixtures($plain, EngineConformanceTestCase::fixtureRows());
        $registry = new IndexRegistry([Indexes::products('manual')]);
        $viaPdo = new Fuzzphony(new PostgresEngine($plain), $registry);
        $viaPdo->schema()->apply($plain);
        $viaPdo->reindex('products');

        $dbal = DoctrineTestCase::dbalConnection();
        $viaDbal = new Fuzzphony(new PostgresEngine(new DbalConnection($dbal)), $registry);

        $expected = array_map(static fn($hit) => $hit->id, $viaPdo->in('products')->query('mose')->get()->hits);
        $found = array_map(static fn($hit) => $hit->id, $viaDbal->in('products')->query('mose')->get()->hits);

        self::assertNotSame([], $expected);
        self::assertSame($expected, $found);
    }
}
