<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Attribute;

use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;

/**
 * Marks a class as a search index. Everything has a sensible default:
 *
 *   #[Searchable]
 *   final class Product { #[SearchField('A', fuzzy: true)] public string $name; }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Searchable
{
    public function __construct(
        /** Index name; defaults to the snake_cased plural of the class name ("Product" -> "products"). */
        public ?string $name = null,
        public string $language = 'english',
        public bool $unaccent = true,
        public SyncMode $sync = SyncMode::Queue,
        /** Source table; defaults to the ORM table name or the snake_cased class name. */
        public ?string $table = null,
        /** Column holding a popularity/priority number used by the "boost" ranking weight. */
        public ?string $boost = null,
        /** Timestamp column used by the "recency" ranking weight. */
        public ?string $recency = null,
        public TriggerLevel $triggerLevel = TriggerLevel::Statement,
    ) {}
}
