<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\Watch;

/** A definition class of a multi-source index: the same index MultiSourceDefinitionTest builds by hand and from YAML. */
#[AsIndexDefinition('site')]
final class SiteMultiSourceIndex
{
    public const string PRODUCT_SQL = 'SELECT id, name AS title, descr AS body, account FROM ms_product';

    public function build(IndexBuilder $b): void
    {
        $b->source('product', self::PRODUCT_SQL, entity: Product::class, watch: [new Watch('ms_product'), new Watch('ms_tag', 'SELECT id FROM ms_product WHERE tag_id = :id', 'id', ['label'])])
            ->sourceFromTable('article', 'ms_article', idType: 'uuid')
            ->field('title', 'A', fuzzy: true)
            ->field('body', 'C')
            ->filter('account', 'int')
            ->tenant('account');
    }
}
