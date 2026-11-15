<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Support\ErrorSummary;
use PHPUnit\Framework\TestCase;

final class EngineFailureChainTest extends TestCase
{
    private function driverError(): \PDOException
    {
        $driver = new \PDOException('invalid input syntax: Key (name)=(secret-row-value) is not valid');
        $driver->errorInfo = ['22P02', 7, 'DETAIL: secret-row-value'];

        return $driver;
    }

    public function testNoRowValueIsAnywhereInTheChainOfAWrappedFailure(): void
    {
        $failure = EngineFailure::wrap('reindex', new \RuntimeException('outer', 0, $this->driverError()));

        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            self::assertStringNotContainsString('secret-row-value', $e->getMessage());
            self::assertSame([], array_filter((array) $e, static fn(mixed $v): bool => is_string($v) && str_contains($v, 'secret-row-value')));
        }
        self::assertStringContainsString('PDOException (SQLSTATE 22P02)', $failure->getMessage());
        self::assertSame('22P02', ErrorSummary::sqlState($failure));
        self::assertTrue(ErrorSummary::isDataError($failure));
        self::assertSame('PDOException (SQLSTATE 22P02)', ErrorSummary::describe($failure));
    }

    public function testTheDriverMessageStaysForSqlFuzzphonyGeneratedItself(): void
    {
        $driver = new \PDOException('relation "x" does not exist');
        $failure = EngineFailure::wrap('schema', $driver, driverMessage: true);

        self::assertSame($driver, $failure->getPrevious());
        self::assertStringContainsString('relation "x" does not exist', $failure->getMessage());
    }

    public function testTheMessageOfAStructuralFailureNamesIdentifiersAndStays(): void
    {
        $driver = new \PDOException("column fr_x does not exist");
        $driver->errorInfo = ["42703", 7, "column fr_x does not exist"];

        $previous = EngineFailure::wrap("search", $driver)->getPrevious();

        self::assertStringContainsString("fr_x", (string) $previous?->getMessage());
        self::assertSame("42703", ErrorSummary::sqlState(EngineFailure::wrap("search", $driver)));
    }
}
