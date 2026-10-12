<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Inspection;

use Fuzzphony\Core\Inspection\InspectOptions;
use PHPUnit\Framework\TestCase;

final class InspectOptionsTest extends TestCase
{
    public function testTheDefaultsAreTheDocumentedThresholds(): void
    {
        $options = new InspectOptions();

        self::assertFalse($options->deep);
        self::assertSame(10_000, $options->maxQueueBacklog);
        self::assertSame(300, $options->maxQueueAgeSeconds);
    }
}
