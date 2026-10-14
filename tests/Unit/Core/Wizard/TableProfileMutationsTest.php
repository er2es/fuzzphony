<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Wizard\TableProfile;
use PHPUnit\Framework\TestCase;

final class TableProfileMutationsTest extends TestCase
{
    public function testAProfileWithoutStatisticsHasNoRowsRelationsOrNotes(): void
    {
        $profile = new TableProfile('article', [], 'id');

        self::assertSame(0, $profile->estimatedRows);
        self::assertSame([], $profile->foreignKeys);
        self::assertSame([], $profile->notes);
    }
}
