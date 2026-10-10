<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Facets;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Search-as-you-type for the search boxes of the demo (assets/controllers/suggest_controller.js).
 *
 * Basic: the word being typed, completed from the index's vocabulary: a JSON list of plain-text search texts that
 * the browser sets as `<datalist>` options.
 *
 * Rich (`rich=1`, the catalogue only): the grouped dropdown of a shop. Completions, the categories the typed text
 * is found in (a facet, counted over the matches of the text with its last word as a prefix), and the first
 * products (with the data that is the application's, brand and price). The three are ordinary calls: suggest(),
 * a search with facets(), and one query for the rows.
 */
final class SuggestController extends AbstractController
{
    /** Like the pages: a longer text is cut, it is a public endpoint. */
    private const int MAX_QUERY = 100;

    #[Route('/suggest', name: 'suggest', methods: ['GET'])]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog, Facets $facets): JsonResponse
    {
        $index = (string) $request->query->get('index', '');
        if (!$fuzzphony->registry()->has($index)) {
            throw $this->createNotFoundException(sprintf('Unknown index "%s".', $index));
        }
        $text = mb_substr((string) $request->query->get('q', ''), 0, self::MAX_QUERY);
        $completions = $fuzzphony->suggest($index, $text, 6);
        if ($index !== 'catalog' || (string) $request->query->get('rich', '') !== '1') {
            return $this->json($completions);
        }

        $categories = $products = [];
        // the word being typed is a prefix: "wireless hea" searches "wireless hea*"
        $prefixed = trim($text) !== '' && preg_match('/[\p{L}\p{N}]$/u', $text) === 1 ? $text . '*' : $text;
        if (trim($prefixed) !== '') {
            try {
                $found = $fuzzphony->in('catalog')->query($prefixed)->limit(5)->facets('category_id')->get();
                $categories = array_slice($facets->categories($found->facets['category_id'] ?? []), 0, 4);
                $rows = $catalog->rows($found->ids());
                foreach ($found->hits as $hit) {
                    $row = $rows[$hit->id] ?? null;
                    if ($row !== null) {
                        $products[] = ['name' => $row['name'], 'brand' => $row['brand'], 'category' => $row['category'], 'price' => $row['price']];
                    }
                }
            } catch (FuzzphonyException) {
                // a text the search refuses is no reason to fail the dropdown: it shows the completions only
            }
        }

        return $this->json(['completions' => $completions, 'categories' => $categories, 'products' => $products]);
    }
}
