<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchQueryMutationsTest extends TestCase
{
    public function testRestrictedFieldsFromAnArrayWithGapsAreAList(): void
    {
        $fields = array_filter(['name', 'x', 'brand'], static fn(string $f): bool => $f !== 'x');
        self::assertSame([0 => 'name', 2 => 'brand'], $fields);

        /** @phpstan-ignore argument.type (a list is documented; the query normalises what it is given) */
        $query = (new SearchQuery('mouse'))->restrictedTo($fields);

        self::assertSame(['name', 'brand'], $query->allowedFields);
    }
}
