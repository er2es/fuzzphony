<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Catalog;
use App\Service\Languages;
use App\Service\SynonymStore;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Edits the synonyms of an index (one list per index, so per language) and tries a word against them.
 * No reindex: Fuzzphony expands them on the query. The list is plain text in the Solr format, so a long
 * one can be pasted in.
 */
final class SynonymsController extends AbstractController
{
    private const int MAX_QUERY = 100;

    /** Index => queries worth trying with the seeded lists. */
    private const array EXAMPLES = [
        'catalog' => ['mice', 'display', 'headset'],
        'lang_en' => ['tv', 'telly', 'laptop'],
        'lang_de' => ['fernseher', 'tv', 'kopfhörer'],
        'lang_fr' => ['télé', 'tv', 'écouteurs'],
        'lang_es' => ['tele', 'televisor', 'auriculares'],
        'lang_hu' => ['tévé', 'televízió', 'fülhallgató'],
    ];

    #[Route('/synonyms', name: 'synonyms', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, SynonymStore $store, Catalog $catalog, Languages $languages): Response
    {
        $indexes = ['catalog' => 'Catalogue (English)'];
        $codes = [];
        foreach (Languages::LANGUAGES as $code => $language) {
            $indexes[$language['index']] = $language['name'];
            $codes[$language['index']] = $code;
        }
        $index = (string) $request->query->get('index', 'catalog');
        if (!isset($indexes[$index])) {
            throw $this->createNotFoundException(sprintf('Unknown index "%s".', $index));
        }
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);

        $problems = [];
        $saved = false;
        $text = null;
        if ($request->isMethod('POST')) {
            $text = str_replace("\r\n", "\n", (string) $request->request->get('body', ''));
            $problems = $store->save($fuzzphony, $index, $text);
            $saved = $problems === [];
        }

        $result = $error = null;
        $names = [];
        if ($q !== '') {
            try {
                $result = $fuzzphony->in($index)->query($q)->limit(10)->get();
            } catch (FuzzphonyException $e) {
                $error = $e->getMessage();
            }
            if ($index === 'catalog') {
                foreach ($catalog->rows($result?->ids() ?? []) as $id => $row) {
                    $names[$id] = $row['name'];
                }
            } else {
                foreach ($languages->products($codes[$index]) as $id => $row) {
                    $names[$id] = $row['name'];
                }
            }
        }

        return $this->render('synonyms.html.twig', [
            'indexes' => $indexes,
            'index' => $index,
            'text' => $text ?? $store->text($index),
            'problems' => $problems,
            'saved' => $saved,
            'q' => $q,
            'result' => $result,
            'names' => $names,
            'error' => $error,
            'examples' => self::EXAMPLES[$index] ?? [],
        ]);
    }
}
