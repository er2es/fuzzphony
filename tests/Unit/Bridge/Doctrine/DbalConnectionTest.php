<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\ParameterType;
use Fuzzphony\Bridge\Doctrine\DbalConnection;
use PHPUnit\Framework\TestCase;

/** How Fuzzphony's parameters reach the application's DBAL connection. */
final class DbalConnectionTest extends TestCase
{
    public function testEachParameterIsBoundWithTheTypeOfItsValueAndWithoutALeadingColon(): void
    {
        $doctrine = $this->createMock(DoctrineConnection::class);
        $doctrine->expects(self::once())->method('fetchAllAssociative')->with(
            'SELECT 1',
            ['flag' => true, 'count' => 5, 'nothing' => null, 'text' => 'x', 'ratio' => 0.5],
            [
                'flag' => ParameterType::BOOLEAN,
                'count' => ParameterType::INTEGER,
                'nothing' => ParameterType::NULL,
                'text' => ParameterType::STRING,
                'ratio' => ParameterType::STRING,
            ],
        )->willReturn([]);

        (new DbalConnection($doctrine))->fetchAll('SELECT 1', [':flag' => true, 'count' => 5, ':nothing' => null, 'text' => 'x', 'ratio' => 0.5]);
    }

    public function testRowsAreReturnedAsAList(): void
    {
        $doctrine = self::createStub(DoctrineConnection::class);
        $doctrine->method('fetchAllAssociative')->willReturn([3 => ['id' => 1], 7 => ['id' => 2]]);

        self::assertSame([['id' => 1], ['id' => 2]], (new DbalConnection($doctrine))->fetchAll('SELECT id'));
    }

    public function testAMissingValueIsNullAndTheTransactionStateIsTheApplications(): void
    {
        $doctrine = self::createStub(DoctrineConnection::class);
        $doctrine->method('fetchOne')->willReturn(false);
        $doctrine->method('isTransactionActive')->willReturn(true);
        $connection = new DbalConnection($doctrine);

        self::assertNull($connection->fetchValue('SELECT 1 WHERE false'));
        self::assertTrue($connection->inTransaction());
    }
}
