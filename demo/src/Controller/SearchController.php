<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Examples;
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
    /** Like the Languages page: the ILIKE fragment is a public endpoint that scans the whole table. */
    private const int MAX_QUERY = 100;

    /** A live answer for fewer letters is "Nothing found" at once: one letter always finds something, and the settle (live_search_controller.js) starts at two. */
    private const int RESCUE_MIN = 2;

    /** What a total or a facet counts at most: the default `limits.candidates`, which the page names where it shows a count as "2,000+". */
    private const int CANDIDATE_CAP = 2000;

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
        // the bar cannot appear under a word that is still being typed. The steps are probes and never logged; the answer is recorded
        // once with Fuzzphony::logSearch(), so the Analytics page sees `mpuse` with the rescued hits, not as a search that found nothing.
        $build = static function (bool $asYouType) use ($fuzzphony, $facets, $q, $category, $stock, $exact): SearchBuilder {
            $search = $fuzzphony->in('catalog')->asSystem()->query($q)->highlight('name')->limit(20)->unlogged();
            $search = $facets->narrow($asYouType ? $search->asYouType() : $search, $category, $stock);

            return $exact ? $search->thresholds(['rescue' => ['correct_when_empty' => false]]) : $search;
        };

        $result = null;
        $rows = [];
        $categories = $stockValues = [];
        $error = null;
        $pending = false;
        $asYouType = true;
        if ($q !== '') {
            try {
                $result = $build(true)->facets('category_id', 'in_stock')->get();
                if ($result->total === 0 && !$exact && ($logged || $rescuing)) {
                    $asYouType = false;
                    $result = $build(false)->facets('category_id', 'in_stock')->get();
                } elseif ($result->total === 0 && !$exact && $live && mb_strlen($q) >= self::RESCUE_MIN) {
                    $pending = true;
                }
                if ($logged && !$pending) {
                    // every run above is unlogged (a probe, a typed prefix): the answer the visitor got is recorded as it is, once,
                    // without running it again (Fuzzphony::logSearch()); a rescued answer is marked, so Analytics tells it from a plain hit
                    $fuzzphony->logSearch('catalog', $q, $result->total, $result->tookMs, usedFuzzy: $result->usedFuzzy, rescued: $result->correctedFrom !== null, system: true);
                }
                $rows = $catalog->rows($result->ids());
                $categories = $facets->categories($result->facets['category_id'] ?? []);
                $stockValues = $result->facets['in_stock'] ?? [];
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
            'asYouType' => $asYouType,
            'warmRuns' => Measure::WARM_RUNS,
            'rowEstimate' => $catalog->estimatedProductCount(),
            'examples' => Examples::HOME,
            'pending' => $pending,
            'cap' => self::CANDIDATE_CAP,
            'correctedTo' => $result?->correctedTo,
        ];

        // the live search asks for the region only
        if ($live) {
            return $this->render('search/_region.html.twig', $data);
        }

        return $this->render('search/index.html.twig', $data);
    }

    /**
     * The comparison bars of the Speed view, measured after the page has rendered (ilike_controller.js): the page itself shows the search's own
     * time at once. Both sides are measured here the same way (one cold run, then the warm median), for the search that answered
     * (`ay`: as you type or the finished text), without its facets: what a search box waits for.
     */
    #[Route('/speed/ilike', name: 'search_ilike')]
    public function ilike(Request $request, Fuzzphony $fuzzphony, Catalog $catalog, Facets $facets): Response
    {
        $q = self::normalizeQuery($request);
        $category = mb_substr(trim((string) $request->query->get('cat', '')), 0, 40);
        $stock = match ((string) $request->query->get('stock', '')) {
            '1' => true,
            '0' => false,
            default => null,
        };
        $asYouType = $request->query->get('ay', '1') !== '0';
        $without = $with = null;
        if ($q !== '') {
            try {
                $with = Measure::median(static fn () => $facets->narrow($fuzzphony->in('catalog')->asSystem()->query($q)->asYouType($asYouType)->limit(20)->unlogged(), $category, $stock)->get());
                $without = Measure::median(static fn (): array => $catalog->ilike($q));
            } catch (FuzzphonyException) {
                // the page above has shown the error; the bars stay empty
            }
        }

        return $this->render('search/_bars.html.twig', ['without' => $without, 'with' => $with, 'q' => $q]);
    }

    private static function normalizeQuery(Request $request): string
    {
        return mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
    }
}
