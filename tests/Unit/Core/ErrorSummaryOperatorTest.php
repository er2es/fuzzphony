<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Support\ErrorSummary;
use PHPUnit\Framework\TestCase;

final class ErrorSummaryOperatorTest extends TestCase
{
    public function testARawDriverExceptionIsSummarisedNotPrintedWithItsMessage(): void
    {
        $raw = new \PDOException('SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type integer: "card 4111111111111111"');

        $shown = ErrorSummary::forOperator($raw);

        self::assertStringNotContainsString('4111111111111111', $shown);
        self::assertStringStartsWith('PDOException', $shown);
    }

    public function testFuzzphonysOwnErrorKeepsItsMessage(): void
    {
        self::assertSame('Bad option.', ErrorSummary::forOperator(new InvalidConfiguration('Bad option.')));
    }

    private static function pdo(string $state): \PDOException
    {
        $e = new \PDOException('driver message');
        $e->errorInfo = [$state, 7, 'detail'];

        return $e;
    }

    public function testOnlyAFiveCharacterSqlStateCounts(): void
    {
        self::assertSame('22P02', ErrorSummary::sqlState(self::pdo('22P02')));
        self::assertNull(ErrorSummary::sqlState(self::pdo('xx22P02')), 'not the end of a longer text');
        self::assertNull(ErrorSummary::sqlState(self::pdo('22P02xx')), 'not the start of a longer text');
        self::assertNull(ErrorSummary::sqlState(self::pdo('22p02')), 'upper case only');
        self::assertSame('23505', ErrorSummary::sqlState(new \RuntimeException('outer', 0, self::pdo('23505'))), 'the state of the failure it wraps');
    }

    public function testADataErrorIsOneOfTheDataClassesOnly(): void
    {
        self::assertTrue(ErrorSummary::isDataError(self::pdo('22P02')));
        self::assertTrue(ErrorSummary::isDataError(self::pdo('23505')));
        self::assertTrue(ErrorSummary::isDataError(self::pdo('54000')));
        self::assertTrue(ErrorSummary::isDataError(self::pdo('P0001')));
        self::assertFalse(ErrorSummary::isDataError(self::pdo('42P01')), 'a missing table is the schema, not the data');
        self::assertFalse(ErrorSummary::isDataError(new \RuntimeException('no state at all')));
    }

    public function testAnEngineFailureNamesTheOperationTheCauseAndTheHint(): void
    {
        $failure = new EngineFailure('outer', 0, self::pdo('42P01'), 'reindex', 'run fuzzphony:schema --apply');

        self::assertSame("Fuzzphony reindex failed: PDOException (SQLSTATE 42P01)
Hint: run fuzzphony:schema --apply", ErrorSummary::forOperator($failure));
        self::assertSame('Fuzzphony reindex failed: PDOException (SQLSTATE 42P01)', ErrorSummary::forOperator(new EngineFailure('outer', 0, self::pdo('42P01'), 'reindex')), 'no hint, no "Hint:" line');
        self::assertSame('PDOException (SQLSTATE 42P01)', ErrorSummary::forOperator($failure, withHint: false));
        self::assertSame('PDOException (SQLSTATE 42P01)', ErrorSummary::forOperator(new EngineFailure('outer', 0, self::pdo('42P01'))), 'an engine failure without an operation is only described');
        self::assertSame('RuntimeException', ErrorSummary::forOperator(new \RuntimeException('secret value')), 'any other failure is only described');
    }
}
