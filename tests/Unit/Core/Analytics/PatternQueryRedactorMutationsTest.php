<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use PHPUnit\Framework\TestCase;

final class PatternQueryRedactorMutationsTest extends TestCase
{
    public function testATextThatCannotBeMatchedIsEmptiedNotAFatalError(): void
    {
        // an invalid UTF-8 byte makes preg_replace() with /u return null: the redactor must not leak the text, nor fail the search
        $redactor = PatternQueryRedactor::fromSettings(['email']);

        self::assertSame('', $redactor->redact("mail \xff ada@example.com"));
    }
}
