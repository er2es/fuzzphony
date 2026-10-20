<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Database;

use Fuzzphony\Core\Database\PdoConnection;
use PHPUnit\Framework\TestCase;

/** Only a pdo_pgsql connection gets the no-server-prepare option; any other PDO driver runs exactly as before. */
final class PdoConnectionTest extends TestCase
{
    public function testAnotherDriverStillRunsParameterisedStatements(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $connection = new PdoConnection(new \PDO('sqlite::memory:'));

        self::assertSame(3, $connection->fetchValue('SELECT :a + :b', ['a' => 1, 'b' => 2]));
        self::assertSame([['v' => 7]], $connection->fetchAll('SELECT :v AS v', ['v' => 7]));
        self::assertSame(0, $connection->execute('CREATE TABLE t (x integer)'));
    }
}
