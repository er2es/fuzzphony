<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\TableProfile;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use PHPUnit\Framework\TestCase;

/** Edge cases of the planner statistics the wizard reads. */
final class PostgresIntrospectorMutationsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_intro_mut CASCADE');
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_intro_mut CASCADE');
    }

    private function profile(): TableProfile
    {
        return (new PostgresIntrospector($this->connection))->describe('fz_intro_mut');
    }

    private static function column(TableProfile $profile, string $name): ColumnProfile
    {
        $column = $profile->column($name);
        self::assertNotNull($column, $name);

        return $column;
    }

    public function testAPositiveDistinctCountIsTakenAsIs(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_mut (id integer PRIMARY KEY, kind text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_intro_mut SELECT i, (ARRAY['a', 'b', 'c'])[i % 3 + 1] FROM generate_series(1, 300) AS i");
        $this->connection->execute('ANALYZE fz_intro_mut');

        $profile = $this->profile();

        self::assertSame([], $profile->notes);
        self::assertSame(3.0, self::column($profile, 'kind')->distinct, 'PostgreSQL stores a positive n_distinct as the count itself, only a negative one is a fraction of the rows');
    }

    public function testNumbersThatAreNotDigitsGiveNoMaximum(): void
    {
        $this->connection->execute('CREATE TABLE fz_intro_mut (id integer PRIMARY KEY, amount numeric NOT NULL, ratio double precision NOT NULL)');
        $this->connection->execute("INSERT INTO fz_intro_mut SELECT i, 'NaN', 'NaN' FROM generate_series(1, 20) AS i");
        $this->connection->execute('ANALYZE fz_intro_mut');

        $profile = $this->profile();

        self::assertSame([], $profile->notes);
        self::assertNull(self::column($profile, 'amount')->approximateMax, 'the statistics hold {NaN}: no number in it');
        self::assertNull(self::column($profile, 'ratio')->approximateMax);
        self::assertSame(20.0, self::column($profile, 'id')->approximateMax);
    }
}
