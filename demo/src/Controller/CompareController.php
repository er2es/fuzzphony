<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
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
        'prefix' => 'ergono*',
    ];

    /** Below, every search measures ILIKE and Fuzzphony, one Measure::median() call each: always these two. */
    private const int ENGINES = 2;

    /**
     * ILIKE takes ~400 ms per run on the 500 000-row catalogue, Fuzzphony ~12 ms; running both before
     * rendering made the whole page wait for the slow one. Fuzzphony is measured here, synchronously, so the
     * page renders at once; the ILIKE column is measured by {@see ilike()}, fetched separately (ilike_controller.js).
     */
    #[Route('/', name: 'compare')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog): Response
    {
        $q = self::normalizeQuery($request);
        $with = null;

        if ($q !== '') {
            $with = Measure::median(static fn () => $fuzzphony->in('catalog')->query($q)->highlight('name')->limit(20)->get());
            $with['rows'] = $catalog->rows($with['value']->ids());
        }

        return $this->render('compare.html.twig', [
            'q' => $q,
            'examples' => self::EXAMPLES,
            'with' => $with,
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
        return trim((string) $request->query->get('q', ''));
    }
}
