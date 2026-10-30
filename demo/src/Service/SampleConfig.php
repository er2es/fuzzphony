<?php

declare(strict_types=1);

namespace App\Service;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;

/**
 * The sample configuration of the Config wizard page ("Load the sample configuration"), in one place: a complete, realistic definition of the
 * demo catalogue that uses every part the wizard can export (a joined source, weighted and typo-tolerant fields, filters, watches, a language,
 * a popularity boost and a recency column, two ranking profiles, thresholds; the synonyms are only explained, see NOTES). The wizard shows it as YAML, builder and attributes
 * (the attribute export has no place for profiles and thresholds and says so); the Tune page can start from its default profile and thresholds.
 *
 * It is loaded through the library's own loader, so it is validated like any configuration: definition() throws an InvalidDefinition for a
 * mistake in CONFIG. It is NOT the index the demo searches (config/packages/fuzzphony.yaml): the numbers differ on purpose, so that loading
 * it changes something in Tune.
 */
final class SampleConfig
{
    public const string NAME = 'catalog';

    /** The array shape of one entry under `fuzzphony.indexes` (docs/configuration.md). */
    public const array CONFIG = [
        'source' => [
            'query' => "SELECT p.id, p.name, p.description, b.name AS brand, c.name AS category,\n"
                . "       p.brand_id, p.category_id, p.price, p.in_stock, p.popularity, p.published_at\n"
                . "FROM bench_product p\n"
                . "JOIN bench_brand b ON b.id = p.brand_id\n"
                . "JOIN bench_category c ON c.id = p.category_id",
        ],
        'fields' => [
            'name' => ['weight' => 'A', 'fuzzy' => true],
            'brand' => ['weight' => 'B', 'fuzzy' => true],
            'category' => 'C',
            'description' => 'D',
        ],
        'filters' => [
            'price' => 'int',
            'in_stock' => 'bool',
            'brand_id' => 'int',
            'category_id' => 'int',
            'published_at' => 'datetime',
        ],
        'watch' => [
            'bench_product' => 'SELECT :id',
            'bench_brand' => 'SELECT id FROM bench_product WHERE brand_id = :id',
            'bench_category' => 'SELECT id FROM bench_product WHERE category_id = :id',
        ],
        'language' => 'english',
        'boost' => 'popularity',
        'recency' => 'published_at',
        'profiles' => [
            'default' => ['fuzzy' => 0.4, 'exact_bonus' => 0.8, 'prefix_bonus' => 0.3, 'boost' => 0.02, 'recency' => 0.15, 'recency_half_life_days' => 45],
            'popular' => ['boost' => 0.05, 'recency' => 0.3, 'recency_half_life_days' => 30],
        ],
        'thresholds' => ['min_score' => 0.02, 'fallback_below' => 8, 'candidate_limit' => 3000],
    ];

    /** The reading guide next to the export: part => [role, why]. */
    public const array NOTES = [
        'source' => ['joined query', 'One row per product, joined with its brand and category: their names become searchable text, their ids become filters.'],
        'name' => ['weight A, typo tolerant', 'The title is what people look for, so it ranks highest and a typo in it is forgiven.'],
        'brand' => ['weight B, typo tolerant', 'A search for "sony headphones" finds the brand; people misspell brands often.'],
        'category' => ['weight C', 'Plain words that describe the product; no typo tolerance, the vocabulary is small and exact.'],
        'description' => ['weight D', 'Long free text: it finds a product but ranks below a title match.'],
        'price, in_stock, brand_id, category_id, published_at' => ['filters', 'Not searched, but usable in where() and as facets: the price range, the stock chip, the brand and category lists, the date.'],
        'watch' => ['sync', 'A change to a brand or a category re-indexes only the products that point at it, not the whole table.'],
        'language' => ['english', 'Words are stemmed (headphone finds headphones), stop words are dropped and accents are folded.'],
        'boost, recency' => ['columns', 'popularity and published_at are the numbers the ranking profiles can weigh; without them the weights below do nothing.'],
        'profiles' => ['default, popular', 'default sets the usual ranking (a stronger exact title bonus, a gentle popularity and recency lift); popular leans on both for a "best sellers first" page: ->profile(\'popular\').'],
        'thresholds' => ['limits', 'A hit needs a relevance of 0.02, typo tolerance joins in when fewer than 8 exact hits are found, and each branch ranks 3 000 candidates.'],
        'synonyms' => ['not in the file', 'The demo keeps its lists (tv, television, telly; mouse, mice) in a database table that the Synonyms page edits and hands to Fuzzphony at run time; a configuration can also list them inline or name a file.'],
    ];

    /** Loads CONFIG through the library's loader (shape and definition are validated). */
    public function definition(): IndexDefinition
    {
        return (new ArrayDefinitionLoader())->load(self::NAME, self::CONFIG);
    }
}
