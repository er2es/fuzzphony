<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;

#[Searchable(tenant: 'account_id')]
final class TenantScopedProduct
{
    public int $id;

    #[SearchField('A')]
    public string $name;

    #[SearchFilter]
    public int $accountId;
}
