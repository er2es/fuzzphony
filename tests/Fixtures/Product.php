<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Definition\SyncMode;

#[Searchable(language: 'hungarian', sync: SyncMode::Trigger, boost: 'popularity', recency: 'publishedAt')]
final class Product
{
    public int $id;

    #[SearchField('A', fuzzy: true)]
    public string $name;

    #[SearchField('D', highlight: false)]
    public ?string $description = null;

    #[SearchFilter]
    public int $price;

    #[SearchFilter]
    public bool $inStock;

    #[SearchFilter]
    public \DateTimeImmutable $publishedAt;

    public float $popularity = 0.0;
}
