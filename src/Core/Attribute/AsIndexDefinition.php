<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Attribute;

/**
 * Marks a class as the definition of one search index, so one entity (or a legacy table without an entity) can have several:
 *
 *   #[AsIndexDefinition('products_admin', entity: Product::class)]
 *   final class ProductAdminIndex
 *   {
 *       public function build(IndexBuilder $b): void
 *       {
 *           $b->fromTable('product')->field('name', 'A', fuzzy: true)->field('sku', 'A', code: true)->filter('price', 'int');
 *       }
 *   }
 *
 * The class needs a public `build(IndexBuilder $builder): void`; the builder it receives already has the name and the entity.
 * build() must be pure and deterministic (no state, no closures kept in the definition): the result is cached in production.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsIndexDefinition
{
    public function __construct(
        /** The index name. */
        public string $name,
        /**
         * The entity the hits of this index are loaded as (EntityLoader, API Platform); null for a legacy table.
         *
         * @var class-string|null
         */
        public ?string $entity = null,
    ) {}
}
