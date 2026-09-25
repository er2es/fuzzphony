<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Messenger;

use Fuzzphony\Bundle\Messenger\RefreshDocuments;
use Fuzzphony\Bundle\Messenger\RefreshDocumentsHandler;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class RefreshDocumentsHandlerTest extends TestCase
{
    public function testInvokingRebuildsTheDocumentsNamedInTheMessage(): void
    {
        $index = Indexes::products();
        $engine = $this->createMock(Engine::class);
        $engine->expects(self::once())->method('refresh')->with($index, [1, 2])->willReturn(2);

        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([$index]));
        $handler = new RefreshDocumentsHandler($fuzzphony);

        $handler(new RefreshDocuments('products', [1, 2]));
    }
}
