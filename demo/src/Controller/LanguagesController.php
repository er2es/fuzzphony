<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Languages;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** One small catalogue in five languages: every search shows what the index's language did to the words. */
final class LanguagesController extends AbstractController
{
    /** Longer input is cut: the page analyses every word, and a sentence is plenty to show that. */
    private const int MAX_QUERY = 100;

    #[Route('/languages', name: 'languages')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, Languages $languages): Response
    {
        $code = (string) $request->query->get('lang', 'en');
        $language = Languages::LANGUAGES[$code] ?? throw $this->createNotFoundException(sprintf('Unknown language "%s".', $code));
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);

        $products = $languages->products($code);
        $result = $error = null;
        $analysis = $ilike = $names = [];
        if ($q !== '') {
            try {
                $result = $fuzzphony->in($language['index'])->query($q)->highlight('name', 'description')->limit(30)->get();
            } catch (FuzzphonyException $e) {
                $error = $e->getMessage();
            }
            foreach ($result->hits ?? [] as $hit) {
                $names[$hit->id] = self::completeHighlight($hit->highlights['name'] ?? null, $products[$hit->id]['name'] ?? null);
            }
            $analysis = $languages->analyse($code, $language, $q);
            $ilike = $languages->ilikeIds($code, $q);
        }

        return $this->render('languages.html.twig', [
            'code' => $code,
            'language' => $language,
            'languages' => Languages::LANGUAGES,
            'q' => $q,
            'products' => $products,
            'result' => $result,
            'error' => $error,
            'analysis' => $analysis,
            'ilike' => $ilike,
            'names' => $names,
        ]);
    }

    /**
     * ts_headline() shortens even a short text: a name like "Cordless Drill 18V" comes back as "Cordless <mark>Drill</mark>"
     * (short words at the edges of a fragment are dropped). When the highlight is one piece of the name, the missing
     * start and end are put back, escaped; otherwise there is no highlight and the template shows the plain name.
     *
     * The highlight itself is HTML the library already escaped (its only tags are <mark>).
     */
    private static function completeHighlight(?string $highlight, ?string $name): ?string
    {
        if ($highlight === null || $name === null) {
            return null;
        }
        $text = html_entity_decode(strip_tags($highlight), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $at = $text === '' ? false : mb_strpos($name, $text);
        if ($at === false) {
            return null;
        }
        $escape = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return $escape(mb_substr($name, 0, $at)) . $highlight . $escape(mb_substr($name, $at + mb_strlen($text)));
    }
}
