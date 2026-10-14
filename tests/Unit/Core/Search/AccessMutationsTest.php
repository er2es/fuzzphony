<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\Access;
use PHPUnit\Framework\TestCase;

final class AccessMutationsTest extends TestCase
{
    public function testRepeatedFieldsAreListedOnceAndTheListStaysAList(): void
    {
        // assertSame compares the keys too: [0 => 'name', 2 => 'brand'] would not be identical
        self::assertSame(['name', 'brand'], Access::only(['name', 'name', 'brand'])->fields);
    }
}
