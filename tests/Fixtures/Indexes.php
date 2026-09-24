<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;

final class Indexes
{
    public static function products(string $sync = 'queue', string $table = 'fz_product'): IndexDefinition
    {
        return IndexDefinition::builder('products')
            ->fromQuery(sprintf(
                'SELECT p.id, p.name, p.description, b.name AS brand, p.price, p.in_stock, p.popularity, p.published_at FROM %s p JOIN fz_brand b ON b.id = p.brand_id',
                $table,
            ))
            ->watch($table)
            ->watch('fz_brand', sprintf('SELECT id FROM %s WHERE brand_id = :id', $table))
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B', fuzzy: true)
            ->field('description', 'D')
            ->filter('price', 'int')
            ->filter('in_stock', 'bool')
            ->filter('published_at', 'datetime')
            ->language('english')
            ->sync($sync)
            ->boostBy('popularity')
            ->recencyBy('published_at')
            ->profile('popular', new RankingProfile(boost: 0.1, recency: 0.3))
            ->build();
    }
}
