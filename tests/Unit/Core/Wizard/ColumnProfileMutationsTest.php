<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use PHPUnit\Framework\TestCase;

final class ColumnProfileMutationsTest extends TestCase
{
    public function testAColumnMayBeNullUnlessSaidOtherwise(): void
    {
        self::assertTrue((new ColumnProfile('title', ColumnKind::Text, 'text'))->nullable);
        self::assertFalse((new ColumnProfile('title', ColumnKind::Text, 'text', nullable: false))->nullable);
    }
}
