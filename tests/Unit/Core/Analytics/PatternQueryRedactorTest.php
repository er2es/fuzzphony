<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Analytics\QueryRedactor;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use PHPUnit\Framework\TestCase;

final class PatternQueryRedactorTest extends TestCase
{
    public function testTheEmailPresetMasksAddresses(): void
    {
        $redactor = PatternQueryRedactor::fromSettings(['email']);

        self::assertInstanceOf(QueryRedactor::class, $redactor);
        self::assertSame('invoice for [redacted] 2026', $redactor->redact('invoice for ada.lovelace+x@example.co.uk 2026'));
        self::assertSame('wireless mouse', $redactor->redact('wireless mouse'));
    }

    public function testARegularExpressionSettingIsUsedAsIs(): void
    {
        $redactor = PatternQueryRedactor::fromSettings(['/\d{4}-\d{4}-\d{4}-\d{4}/']);

        self::assertSame('card [redacted] expired', $redactor->redact('card 1234-5678-9012-3456 expired'));
    }

    public function testEveryPatternIsAppliedAndTheReplacementCanBeChosen(): void
    {
        $redactor = new PatternQueryRedactor(['/\d+/', '/secret/'], '*');

        self::assertSame('a * b *', $redactor->redact('a 12 b secret'));
    }

    public function testAnInvalidPatternIsRefusedWhenTheRedactorIsBuilt(): void
    {
        $this->expectException(InvalidConfiguration::class);
        PatternQueryRedactor::fromSettings(['/unterminated']);
    }
}
