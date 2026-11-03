<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandCompletionTester;

/** The shell completion of fuzzphony:analytics offers the index names and the allowed option values. */
final class AnalyticsCommandCompletionTest extends TestCase
{
    public function testItSuggestsTheIndexesTheStatusesAndTheFormats(): void
    {
        $fuzzphony = new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products('manual')]));
        $completion = new CommandCompletionTester(new AnalyticsCommand($fuzzphony));

        self::assertSame(['products'], $completion->complete(['']));
        self::assertSame(['ok', 'denied', 'rate_limited'], $completion->complete(['products', '--status', '']));
        self::assertSame(['table', 'json'], $completion->complete(['products', '--format', '']));
    }
}
