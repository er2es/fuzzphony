<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Engine\Postgres\Highlighter;
use Fuzzphony\Tests\Fixtures\Indexes;
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

    public function testNoFieldsOrNoIdsShortCircuitsToAnEmptyResultWithoutTouchingTheDatabase(): void
    {
        $highlighter = new Highlighter($this->neverCalledConnection());

        self::assertSame([], $highlighter->highlight(Indexes::products(), [], "'mouse'", [1]));
        self::assertSame([], $highlighter->highlight(Indexes::products(), ['name'], "'mouse'", []));
    }

    public function testHighlightingAnUnknownFieldThrows(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('Cannot highlight "nosuch": it is not a highlightable field of index "products".');

        (new Highlighter($this->neverCalledConnection()))->highlight(Indexes::products(), ['nosuch'], "'mouse'", [1]);
    }

    private function neverCalledConnection(): Connection
    {
        return new class implements Connection {
            public function fetchAll(string $sql, array $params = []): array
            {
                throw new \LogicException('must not be called');
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                throw new \LogicException('must not be called');
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('must not be called');
            }

            public function transactional(callable $callback): mixed
            {
                throw new \LogicException('must not be called');
            }
        };
    }
}
