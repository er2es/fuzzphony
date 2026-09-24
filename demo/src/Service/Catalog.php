<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Plain SQL helpers of the demo: the naive ILIKE search, and product rows for result ids. */
final readonly class Catalog
{
    public function __construct(private Connection $connection) {}

    /** @return list<array{id: int, name: string, brand: string, price: int}> */
    public function ilike(string $text, int $limit = 20): array
    {
        $pattern = '%' . implode('%', preg_split('/\s+/', trim(addcslashes($text, '%_\\'))) ?: []) . '%';

        /** @var list<array{id: int, name: string, brand: string, price: int}> */
        return $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, b.name AS brand, p.price
             FROM bench_product p JOIN bench_brand b ON b.id = p.brand_id
             WHERE p.name ILIKE :q OR p.description ILIKE :q
             LIMIT :limit',
            ['q' => $pattern, 'limit' => $limit],
        );
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int|string, array{id: int, name: string, brand: string, category: string, price: int, popularity: float}>
     */
    public function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, b.name AS brand, c.name AS category, p.price, p.popularity
             FROM bench_product p JOIN bench_brand b ON b.id = p.brand_id JOIN bench_category c ON c.id = p.category_id
             WHERE p.id IN (:ids)',
            ['ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_column($rows, null, 'id');
    }
}
