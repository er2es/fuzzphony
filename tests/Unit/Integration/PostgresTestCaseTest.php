<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Integration;

use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PostgresTestCase::assertDisposable() is the single guard every integration test DSN goes
 * through (see PostgresTestCase::dsn()); it never connects, so it is tested here as a pure
 * function, in the unit suite, without a real database.
 */
final class PostgresTestCaseTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedDsns(): iterable
    {
        yield 'exact name' => ['pgsql:host=127.0.0.1;dbname=test;user=x'];
        yield 'suffixed' => ['pgsql:host=127.0.0.1;dbname=fuzzphony_test;user=x'];
        yield 'prefixed' => ['pgsql:host=127.0.0.1;dbname=test_fuzzphony;user=x'];
        yield 'per-worker suffix' => ['pgsql:host=127.0.0.1;dbname=fuzzphony_test_t3;user=x'];
        yield 'different case' => ['pgsql:host=127.0.0.1;dbname=Fuzzphony_TEST;user=x'];
        // "contest" contains "test" as a substring; the rule is a plain substring check, on
        // purpose (see PostgresTestCase::assertDisposable()), so this is accepted too.
        yield 'substring false positive ("contest")' => ['pgsql:host=127.0.0.1;dbname=contest;user=x'];
    }

    #[DataProvider('acceptedDsns')]
    public function testAcceptsDatabaseNamesContainingTest(string $dsn): void
    {
        PostgresTestCase::assertDisposable($dsn);
        $this->addToAssertionCount(1); // no exception: that is the pass
    }

    /** @return iterable<string, array{string}> */
    public static function refusedDsns(): iterable
    {
        yield 'production-looking name' => ['pgsql:host=127.0.0.1;dbname=fuzzphony;user=x'];
        yield 'another unrelated name' => ['pgsql:host=127.0.0.1;dbname=prod;user=x'];
        yield 'missing dbname' => ['pgsql:host=127.0.0.1;user=x'];
    }

    #[DataProvider('refusedDsns')]
    public function testRefusesDatabaseNamesWithoutTest(string $dsn): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(self::expectedMessage($dsn));

        PostgresTestCase::assertDisposable($dsn);
    }

    public function testTheRefusalMessageNamesTheDatabase(): void
    {
        try {
            PostgresTestCase::assertDisposable('pgsql:host=127.0.0.1;dbname=fuzzphony;user=x');
            self::fail('assertDisposable() should have refused "fuzzphony".');
        } catch (AssertionFailedError $e) {
            self::assertSame(
                'Refusing to run destructive integration tests against database "fuzzphony": its name must contain "test" (see CONTRIBUTING.md).',
                $e->getMessage(),
            );
        }
    }

    private static function expectedMessage(string $dsn): string
    {
        $matched = preg_match('/dbname=([^;]+)/', $dsn, $match);
        $name = $matched === 1 ? $match[1] : '';

        return sprintf('Refusing to run destructive integration tests against database "%s": its name must contain "test" (see CONTRIBUTING.md).', $name);
    }
}
