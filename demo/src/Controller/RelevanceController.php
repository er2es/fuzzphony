<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Relevance: what 0.7 changed in matching quality, on the catalogue. One query, searched with the typo
 * tolerance that depends on the word's length (the default) and with a flat 0.3 (what 0.6 did), then what
 * the synonyms of the catalogue and the vocabulary made of it.
 */
final class RelevanceController extends AbstractController
{
    /** Group => label => query. */
    public const array EXAMPLES = [
        'Typo tolerance by word length' => [
            'a correct short word' => 'mouse',
            'a typo in a four-letter word' => 'mose',
            'a typo in a long word' => 'hedphones',
            'a typo and a correct word' => 'wireles mouse',
            'the price: a letter replaced in the middle' => 'mpuse',
        ],
        'Synonyms' => [
            'an irregular plural' => 'mice',
            'another word for a monitor' => 'display',
            'a headset' => 'headset',
            'one way only: a drill finds screwdrivers' => 'drill',
            '... but not the other way round' => 'screwdriver',
        ],
        'Did you mean' => [
            'a misspelled word' => 'hedphones',
            'inside a query' => 'ergonmic -silent',
            'a phrase' => '"noise cancelling" headphnoes',
            'a word of the vocabulary: nothing to suggest' => 'headphones',
        ],
    ];

    private const int MAX_QUERY = 100;

    #[Route('/relevance', name: 'relevance')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Catalog $catalog): Response
    {
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
        $byLength = $flat = null;
        if ($q !== '') {
            // always: typo tolerance runs even when exact matching finds plenty, so the two columns differ only by the similarity rule
            $search = $fuzzphony->in('catalog')->query($q)->highlight('name')->limit(8)->thresholds(['fuzzy_mode' => 'always']);
            $byLength = $search->get();
            $flat = $search->thresholds(['fuzzy_similarity' => 0.3])->get();
        }
        $rows = $catalog->rows(array_merge($byLength?->ids() ?? [], $flat?->ids() ?? []));

        return $this->render('relevance.html.twig', [
            'q' => $q,
            'examples' => self::EXAMPLES,
            'byLength' => $byLength,
            'flat' => $flat,
            'rows' => $rows,
            'suggestion' => $this->suggestion($fuzzphony, $q),
        ]);
    }

    /** The suggestion of the search as an application would run it (typo tolerance in its default mode). */
    private function suggestion(Fuzzphony $fuzzphony, string $q): ?SearchResult
    {
        return $q === '' ? null : $fuzzphony->in('catalog')->query($q)->limit(1)->get();
    }
}
