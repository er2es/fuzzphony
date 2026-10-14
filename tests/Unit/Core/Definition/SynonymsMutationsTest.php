<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\Synonyms;
use PHPUnit\Framework\TestCase;

final class SynonymsMutationsTest extends TestCase
{
    public function testACarriageReturnInsideALineIsDropped(): void
    {
        $synonyms = Synonyms::fromText("tv, tele\rvision\r\nlaptop => note\rbook\r\n");

        self::assertSame([['tv', 'television'], 'laptop => notebook'], $synonyms->toEntries());
    }
}
