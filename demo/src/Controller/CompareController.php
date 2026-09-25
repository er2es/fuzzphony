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

    #[Route('/', name: 'compare')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $without = $with = null;

        if ($q !== '') {
            $without = Measure::median(static fn (): array => $catalog->ilike($q));
            $with = Measure::median(static fn () => $fuzzphony->in('catalog')->query($q)->highlight('name')->limit(20)->get());
            $with['rows'] = $catalog->rows($with['value']->ids());
        }

        return $this->render('compare.html.twig', [
            'q' => $q,
            'examples' => self::EXAMPLES,
            'without' => $without,
            'with' => $with,
            'table' => Catalog::TABLE,
            'rowEstimate' => $catalog->estimatedProductCount(),
            'warmRuns' => Measure::WARM_RUNS,
            'engines' => self::ENGINES,
        ]);
    }
}
