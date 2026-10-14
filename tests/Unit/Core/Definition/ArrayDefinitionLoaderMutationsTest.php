<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;

final class ArrayDefinitionLoaderMutationsTest extends TestCase
{
    /** @return list<string> */
    private static function logViolations(mixed $value): array
    {
        try {
            (new ArrayDefinitionLoader())->load('things', ['fields' => ['name' => 'A'], 'log' => $value]);
        } catch (InvalidDefinition $e) {
            return $e->violations;
        }
        self::fail('Expected an InvalidDefinition');
    }

    public function testAStringLogValueIsQuotedInTheErrorSoTheTypoIsVisible(): void
    {
        self::assertSame(['"log" must be true or false (a boolean, not a string), got \'false\'.'], self::logViolations('false'));
    }

    public function testANumberIsShownAsItsValue(): void
    {
        self::assertSame(['"log" must be true or false (a boolean, not a string), got 0.'], self::logViolations(0));
    }

    public function testAListIsNamedByItsTypeNotDumped(): void
    {
        self::assertSame(['"log" must be true or false (a boolean, not a string), got array.'], self::logViolations([true]));
    }
}
