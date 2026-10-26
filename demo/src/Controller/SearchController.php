<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Facets;
use App\Service\Measure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchBuilder;
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

    /** A live answer for fewer letters is "Nothing found" at once: one letter always finds something, and the settle (live_search_controller.js) starts at two. */
    private const int RESCUE_MIN = 2;

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

        // the live search sends a request per pause in typing: those are not logged (the half-typed words would fill the log);
        // the settled query is: a full page load, or the live search's one silent repeat after the text has stood for a moment
        $live = $request->headers->get('X-Requested-With') === 'fetch';
        $logged = !$live || $request->headers->get('X-Settled') === '1';
        // a live answer that found nothing is followed at once by one more request marked X-Rescue (unlogged), which may rescue it
        $rescuing = $live && $request->headers->get('X-Rescue') === '1';

        // "Search for <what I typed> instead" (the link of the bar): the text is searched as typed, without the zero-result rescue
        $exact = $request->query->getBoolean('exact');

        // The field searches as you type (the last word also matches as a beginning), and the library never rescues such a search:
        // the last word is not finished. The rescue is for a text that found nothing even so: the text once more as a finished one, where
        // a typo with a unique one-edit correction (`mpuse` -> `mouse`) is rescued (SearchResult::$correctedFrom). A search the visitor
        // means ($logged: a page load, a chip, Enter, or typing that has SETTLED, see live_search_controller.js) does both steps in this
        // request. While the visitor is still typing, an empty answer is not shown as "Nothing found": the page says it is looking for a
        // correction ($pending) and the browser asks at once for the rescue (X-Rescue); a word that finds results is never rescued, so
        // the bar cannot appear under a word that is still being typed. The first step is a probe and never logged; the search that
        // answers is logged (once), so the Analytics page sees `mpuse` with the rescued hits, not as a search that found nothing.
        $build = static function (bool $asYouType, bool $log) use ($fuzzphony, $facets, $q, $category, $stock, $exact): SearchBuilder {
            $search = $fuzzphony->in('catalog')->query($q)->highlight('name')->limit(20);
            $search = $facets->narrow($asYouType ? $search->asYouType() : $search, $category, $stock);
            $search = $log ? $search : $search->unlogged();

            return $exact ? $search->thresholds(['rescue_empty' => false]) : $search;
        };

        $result = null;
        $rows = [];
        $categories = $stockValues = [];
        $error = null;
        $speed = null;
        $pending = false;
        if ($q !== '') {
            try {
                $asYouType = true;
                $result = $build(true, false)->facets('category_id', 'in_stock')->get();
                if ($result->total === 0 && !$exact && ($logged || $rescuing)) {
                    $asYouType = false;
                    $result = $build(false, $logged)->facets('category_id', 'in_stock')->get();
                } elseif ($result->total === 0 && !$exact && $live && mb_strlen($q) >= self::RESCUE_MIN) {
                    $pending = true;
                } elseif ($logged) {
                    $result = $build(true, true)->facets('category_id', 'in_stock')->get();
                }
                $rows = $catalog->rows($result->ids());
                $categories = $facets->categories($result->facets['category_id'] ?? []);
                $stockValues = $result->facets['in_stock'] ?? [];
                if ($view === 'speed' && !$pending) {
                    // the measured search is the one that answered, without facets: what a search box waits for
                    $speed = Measure::median(static fn () => $facets->narrow($fuzzphony->in('catalog')->query($q)->asYouType($asYouType)->limit(20)->unlogged(), $category, $stock)->get());
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
            'exact' => $exact,
            'error' => $error,
            'speed' => $speed,
            'warmRuns' => Measure::WARM_RUNS,
            'rowEstimate' => $catalog->estimatedProductCount(),
            'examples' => self::TRY,
            'pending' => $pending,
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
