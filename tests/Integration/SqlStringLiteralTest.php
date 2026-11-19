<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A literal from Sql::string() reads back as the same text whatever standard_conforming_strings says. */
final class SqlStringLiteralTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function values(): iterable
    {
        yield 'plain' => ['plain'];
        yield 'quote' => ["o'reilly"];
        yield 'backslash quote' => ["a\\' OR 1=1 --"];
        yield 'backslashes' => ['C:\\path\\n\\\\x'];
        yield 'trailing backslash' => ['ends\\'];
    }

    #[DataProvider('values')]
    public function testTheLiteralIsTheValueWithEitherSetting(string $value): void
    {
        $connection = PostgresTestCase::connect();
        foreach (['on', 'off'] as $setting) {
            $connection->execute(sprintf('SET standard_conforming_strings = %s', $setting));
            self::assertSame($value, Coerce::str($connection->fetchValue('SELECT ' . Sql::string($value))), $setting);
        }
        $connection->execute('RESET standard_conforming_strings');
    }

    public function testAValueWithoutBackslashKeepsThePlainLiteral(): void
    {
        self::assertSame("'o''reilly'", Sql::string("o'reilly"));
    }
}
