<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Inspection;

use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectionReport;
use PHPUnit\Framework\TestCase;

final class InspectionReportMutationsTest extends TestCase
{
    public function testProblemsIsAListEvenWhenHealthyChecksComeFirst(): void
    {
        $warning = Check::warning('b', 'careful');
        $error = Check::error('c', 'broken');
        $report = new InspectionReport('products', [Check::ok('a', 'fine'), $warning, Check::skipped('s', 'n/a'), $error]);

        self::assertSame([$warning, $error], $report->problems(), 'keys are renumbered from 0: the result is a list');
    }
}
