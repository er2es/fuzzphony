<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Facets;
use App\Service\Languages;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Search-as-you-type for every search box of the demo (assets/controllers/suggest_controller.js): the same dropdown
 * everywhere, built from three ordinary calls: the completions of the word being typed (suggest()), the first hits of
 * the same text matched as you type (asYouType()), and for the catalogue the categories it is found in (a facet).
 * Brand, price and category name are the application's data, loaded for the ids the search returned.
 *
 * A JSON object of plain text: the browser sets it with textContent, never as HTML.
 */
final class SuggestController extends AbstractController
{
    /** Like the pages: a longer text is cut, it is a public endpoint. */
    private const int MAX_QUERY = 100;

    #[Route('/suggest', name: 'suggest', methods: ['GET'])]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog, Facets $facets, Languages $languages): JsonResponse
    {
        $index = (string) $request->query->get('index', '');
        if (!$fuzzphony->registry()->has($index)) {
            throw $this->createNotFoundException(sprintf('Unknown index "%s".', $index));
        }
        $text = mb_substr((string) $request->query->get('q', ''), 0, self::MAX_QUERY);
        $completions = $fuzzphony->suggest($index, $text, 6);
        $categories = $products = [];

        if (trim($text) !== '') {
            try {
                $search = $fuzzphony->in($index)->query($text)->asYouType()->limit(5);
                if ($index === 'catalog') {
                    $found = $search->facets('category_id')->get();
                    $categories = array_slice($facets->categories($found->facets['category_id'] ?? []), 0, 4);
                    $rows = $catalog->rows($found->ids());
                    foreach ($found->hits as $hit) {
                        if (isset($rows[$hit->id])) {
                            $row = $rows[$hit->id];
                            $products[] = ['name' => $row['name'], 'note' => sprintf('%s · %s · %s Ft', $row['brand'], $row['category'], number_format($row['price'], 0, '.', ' '))];
                        }
                    }
                } else {
                    $found = $search->get();
                    $code = array_search($index, array_column(Languages::LANGUAGES, 'index'), true);
                    $rows = $code !== false ? $languages->products((string) array_keys(Languages::LANGUAGES)[$code]) : [];
                    foreach ($found->hits as $hit) {
                        if (isset($rows[$hit->id])) {
                            $products[] = ['name' => $rows[$hit->id]['name'], 'note' => $rows[$hit->id]['category']];
                        }
                    }
                }
            } catch (FuzzphonyException) {
                // a text the search refuses is no reason to fail the dropdown: it shows the completions only
            }
        }

        return $this->json(['completions' => $completions, 'categories' => $categories, 'products' => $products]);
    }
}
