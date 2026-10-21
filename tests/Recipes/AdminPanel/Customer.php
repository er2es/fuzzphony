<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\AdminPanel;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Core\Attribute\Searchable;
use Fuzzphony\Core\Attribute\SearchField;
use Fuzzphony\Core\Attribute\SearchFilter;
use Fuzzphony\Core\Definition\SyncMode;

// region: entity
#[ORM\Entity]
#[ORM\Table(name: 'recipe_customer')]
#[ApiResource]
#[ApiFilter(FuzzphonySearchFilter::class)]                                   // ?q=smiht
#[ApiFilter(BooleanFilter::class, properties: ['active'])]                   // ?active=true
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]          // ?order[name]=asc
#[Searchable(language: 'english', sync: SyncMode::Orm)]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        #[SearchField('A', fuzzy: true)]
        public string $name,
        #[ORM\Column]
        #[SearchField('B', fuzzy: true)]
        public string $company,
        #[ORM\Column]
        #[SearchField('D')]
        public string $city,
        #[ORM\Column]
        #[SearchFilter]
        public bool $active = true,
        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
// endregion
