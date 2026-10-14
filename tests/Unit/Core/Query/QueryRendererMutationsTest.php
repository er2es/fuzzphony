<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\QueryRenderer;
use PHPUnit\Framework\TestCase;

final class QueryRendererMutationsTest extends TestCase
{
    public function testANodeOfAnUnknownKindRendersAsNothing(): void
    {
        $custom = new class implements Node {
            public function __toString(): string
            {
                return 'custom';
            }
        };

        self::assertSame('', QueryRenderer::render($custom));
    }
}
