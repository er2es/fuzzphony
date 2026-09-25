<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Engine;

use Fuzzphony\Core\Engine\Capabilities;
use Fuzzphony\Core\Engine\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase
{
    public function testIsNativeIsTrueOnlyForNativelySupportedCapabilities(): void
    {
        $capabilities = new Capabilities([Capability::FullText], [Capability::Fuzzy->value => 'trigram similarity']);

        self::assertTrue($capabilities->isNative(Capability::FullText));
        self::assertFalse($capabilities->isNative(Capability::Fuzzy), 'emulated, not native');
        self::assertFalse($capabilities->isNative(Capability::Highlight));
    }

    public function testSupportsIncludesEmulatedCapabilities(): void
    {
        $capabilities = new Capabilities([Capability::FullText], [Capability::Fuzzy->value => 'trigram similarity']);

        self::assertTrue($capabilities->supports(Capability::FullText));
        self::assertTrue($capabilities->supports(Capability::Fuzzy));
        self::assertFalse($capabilities->supports(Capability::Highlight));
    }
}
