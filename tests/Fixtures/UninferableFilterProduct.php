<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;

/** A #[SearchFilter] with no explicit type on an untyped property: the loader cannot infer one. */
#[Searchable]
final class UninferableFilterProduct
{
    public int $id;

    #[SearchField('A')]
    public string $name;

    /** @var mixed intentionally untyped: the loader must not be able to infer a filter type */
    #[SearchFilter]
    public $tags;
}
