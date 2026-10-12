<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Facets;
use App\Service\Measure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The home page: one search field over the catalogue. The results appear in place while you type (the page answers a
 * request marked `X-Requested-With: fetch` with just the results region), with the facets of the result at the side; the
 * Speed view measures the same search against a plain ILIKE and runs the benchmark of nine queries.
 */
final class SearchController extends AbstractController
{
    /** The tokens under the field: label => query, grouped by what they show. */
    public const array EXAMPLES = [
        'accent' => 'creme',
        'typo' => 'hedphones',
        'stemming' => 'drills',
        'phrase + exclude' => '"noise cancelling" -headphones',
        'field' => 'category:kitchen kettle',
        'exact field' => 'brand:sony headphones',
        'prefix' => 'ergono*',
    ];

    /** What the field's suggestions suggest to try: the three things the demo is about. */
    private const array TRY = [
        'Typos' => 'hedphones',
        'Beginnings' => 'cr',
        'Meaning' => 'tv',
        'Accents' => 'creme',
        'Word forms' => 'drills',
    ];

    /** Like the Languages page: the ILIKE fragment is a public endpoint that scans the whole table. */
    private const int MAX_QUERY = 100;

    #[Route('/', name: 'search')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog, Facets $facets): Response
    {
        $q = self::normalizeQuery($request);
        $view = (string) $request->query->get('view') === 'speed' ? 'speed' : 'results';
        $category = mb_substr(trim((string) $request->query->get('cat', '')), 0, 40);
        $stock = match ((string) $request->query->get('stock', '')) {
            '1' => true,
            '0' => false,
            default => null,
        };

        // the live search sends a request per pause in typing: only the settled query of a full page load is logged
        $live = $request->headers->get('X-Requested-With') === 'fetch';

        $result = null;
        $rows = [];
        $categories = $stockValues = [];
        $error = null;
        $speed = null;
        if ($q !== '') {
            try {
                $search = $fuzzphony->in('catalog')->query($q)->asYouType()->highlight('name')->limit(20);
                $search = $facets->narrow($live ? $search->unlogged() : $search, $category, $stock);
                $result = $search->facets('category_id', 'in_stock')->get();
                $rows = $catalog->rows($result->ids());
                $categories = $facets->categories($result->facets['category_id'] ?? []);
                $stockValues = $result->facets['in_stock'] ?? [];
                if ($view === 'speed') {
                    // the measured search is the plain one (no facets): what a search box waits for
                    $speed = Measure::median(static fn () => $facets->narrow($fuzzphony->in('catalog')->query($q)->asYouType()->limit(20)->unlogged(), $category, $stock)->get());
                }
            } catch (FuzzphonyException $e) {
                $error = $e->getMessage();
            }
        }

        $data = [
            'q' => $q,
            'view' => $view,
            'result' => $result,
            'rows' => $rows,
            'categories' => $categories,
            'stock_values' => $stockValues,
            'category' => $category,
            'stock' => $stock,
            'error' => $error,
            'speed' => $speed,
            'warmRuns' => Measure::WARM_RUNS,
            'rowEstimate' => $catalog->estimatedProductCount(),
            'examples' => self::TRY,
        ];

        // the live search asks for the region only
        if ($live) {
            return $this->render('search/_region.html.twig', $data);
        }

        return $this->render('search/index.html.twig', $data);
    }

    /** The comparison bars of the Speed view: the ILIKE side is measured here, fetched after the page has rendered (ilike_controller.js). */
    #[Route('/speed/ilike', name: 'search_ilike')]
    public function ilike(Request $request, Catalog $catalog): Response
    {
        $q = self::normalizeQuery($request);
        $without = $q !== '' ? Measure::median(static fn (): array => $catalog->ilike($q)) : null;

        return $this->render('search/_bars.html.twig', [
            'without' => $without,
            'fuzzphony_ms' => max(0.01, (float) $request->query->get('fz', '0')),
            'q' => $q,
        ]);
    }

    /** @return array<string, string> label => query, the nine queries of the benchmark */
    public static function benchmarkQueries(): array
    {
        return self::EXAMPLES + ['plain word' => 'wireless', 'two words' => 'wireless mouse'];
    }

    private static function normalizeQuery(Request $request): string
    {
        return mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
    }
}
