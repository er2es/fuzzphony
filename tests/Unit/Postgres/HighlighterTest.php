<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\Highlighter;
use PHPUnit\Framework\TestCase;

final class HighlighterTest extends TestCase
{
    public function testSourceTextIsEscapedAndOnlyMarkersBecomeTags(): void
    {
        $marked = "<img src=x onerror=alert(1)> \u{E000}mouse\u{E001} & \"cable\"";

        self::assertSame(
            '&lt;img src=x onerror=alert(1)&gt; <mark>mouse</mark> &amp; &quot;cable&quot;',
            Highlighter::toHtml($marked),
        );
    }
}
