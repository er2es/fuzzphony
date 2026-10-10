<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Facets;
use App\Service\Measure;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompareController extends AbstractController
{
    public const array EXAMPLES = [
        'accent' => 'creme',
        'typo' => 'hedphones',
        'stemming' => 'drills',
        'phrase + exclude' => '"noise cancelling" -headphones',
        'field' => 'category:kitchen kettle',
        'exact field' => 'brand:sony headphones',
        'prefix' => 'ergono*',
    ];

    /** Below, every search measures ILIKE and Fuzzphony, one Measure::median() call each: always these two. */
    private const int ENGINES = 2;

    /** Like the Languages page: the ILIKE fragment is a public endpoint that scans the whole table. */
    private const int MAX_QUERY = 100;

    /**
     * ILIKE takes ~400 ms per run on the 500 000-row catalogue, Fuzzphony ~12 ms; running both before
     * rendering made the whole page wait for the slow one. Fuzzphony is measured here, synchronously, so the
     * page renders at once; the ILIKE column is measured by {@see ilike()}, fetched separately (ilike_controller.js).
     */
    #[Route('/', name: 'compare')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog, Facets $facets): Response
    {
        $q = self::normalizeQuery($request);
        $category = mb_substr(trim((string) $request->query->get('cat', '')), 0, 40);
        $stock = match ((string) $request->query->get('stock', '')) {
            '1' => true,
            '0' => false,
            default => null,
        };
        $with = null;
        $facetValues = null;

        if ($q !== '') {
            // what is measured is the search (narrowed by the facets chosen); the facets are counted by one more, unmeasured call
            $with = Measure::median(fn () => $facets->narrow($fuzzphony->in('catalog')->query($q)->highlight('name')->limit(20), $category, $stock)->get());
            $with['rows'] = $catalog->rows($with['value']->ids());
            $counted = $fuzzphony->in('catalog')->query($q)->limit(1)->facets('category_id', 'in_stock');
            $counted = $facets->narrow($counted, $category, $stock)->get();
            $facetValues = [
                'categories' => $facets->categories($counted->facets['category_id'] ?? []),
                'stock' => $counted->facets['in_stock'] ?? [],
                'tookMs' => $counted->tookMs,
                'lowerBound' => $counted->totalIsLowerBound,
            ];
        }

        return $this->render('compare.html.twig', [
            'q' => $q,
            'examples' => self::EXAMPLES,
            'with' => $with,
            'facets' => $facetValues,
            'category' => $category,
            'stock' => $stock,
            'table' => Catalog::TABLE,
            'rowEstimate' => $catalog->estimatedProductCount(),
            'warmRuns' => Measure::WARM_RUNS,
            'engines' => self::ENGINES,
        ]);
    }

    /** The ILIKE column, fetched by ilike_controller.js after the page above has already rendered. */
    #[Route('/compare/ilike', name: 'compare_ilike')]
    public function ilike(Request $request, Catalog $catalog): Response
    {
        $q = self::normalizeQuery($request);
        $without = $q !== '' ? Measure::median(static fn (): array => $catalog->ilike($q)) : null;

        return $this->render('compare/_ilike.html.twig', ['without' => $without]);
    }

    /** Same normalisation on both routes, so the ILIKE fragment always matches what the page asked to search for. */
    private static function normalizeQuery(Request $request): string
    {
        return mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
    }
}
