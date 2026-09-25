<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\ApiPlatform;

use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use PHPUnit\Framework\TestCase;

final class FuzzphonySearchFilterTest extends TestCase
{
    public function testGetDescriptionDocumentsTheParameterAsAnOpenApiProperty(): void
    {
        $fuzzphony = new Fuzzphony(self::createStub(Engine::class), new IndexRegistry());
        $filter = new FuzzphonySearchFilter($fuzzphony, parameterName: 'search');

        self::assertSame([
            'search' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Relevance search: typo tolerant, accent insensitive. Supports "phrases", -exclusions, OR, prefix* and field:value.',
            ],
        ], $filter->getDescription(Article::class));
    }
}
