<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\Shop;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\FederatedResult;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;

/** What the shop's pages ask of Fuzzphony: the listing, the search box's dropdown, and the "everything" page. */
final class Storefront
{
    public function __construct(private readonly Fuzzphony $fuzzphony) {}

    // region: listing
    /** The listing page: hits, the facets of the sidebar and a did-you-mean link. */
    public function listing(string $text, ?int $categoryId = null, string $sort = 'default'): SearchResult
    {
        $search = $this->fuzzphony->in('products')
            ->query($text)
            ->profile(in_array($sort, ['default', 'popular'], true) ? $sort : 'default')   // never pass raw input to profile()
            ->facets('category_id', 'in_stock')
            ->facetValues(10)
            ->highlight('name')
            ->limit(24);
        if ($categoryId !== null) {
            $search = $search->where('category_id', $categoryId);   // the category facet still lists the other categories
        }

        return $search->get();
    }
    // endregion

    // region: dropdown
    /**
     * The dropdown under the search box while the visitor types.
     *
     * @return array{completions: list<string>, products: list<Hit>, categories: list<FacetValue>}
     */
    public function dropdown(string $text): array
    {
        $found = $this->fuzzphony->in('products')
            ->query($text)
            ->asYouType()        // the last word also matches as the beginning of a longer one
            ->unlogged()         // a search per keystroke is not what people searched for
            ->facets('category_id')
            ->limit(5)
            ->get();

        return [
            'completions' => $this->fuzzphony->suggest('products', $text, 5),   // "wireless hea" -> "wireless headphones"
            'products' => $found->hits,                                         // load name, price and picture by $hit->id
            'categories' => $found->facets['category_id'] ?? [],                // label the ids yourself
        ];
    }
    // endregion

    // region: everything
    /** Products (in stock, twice the weight) and categories in one merged list. */
    public function everything(string $text): FederatedResult
    {
        return $this->fuzzphony->federated()
            ->index('products', weight: 2.0, configure: static fn(SearchBuilder $b): SearchBuilder => $b->where('in_stock', true)->highlight('name'))
            ->index('categories')
            ->query($text)
            ->limit(10)
            ->get();
    }
    // endregion
}
