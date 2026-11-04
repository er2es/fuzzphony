<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DemoUser;
use App\Service\Languages;
use App\Service\Magic;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The search box in the header of every page (assets/controllers/magic_controller.js): one text, searched in EVERY index of the demo at
 * once with a federated search (people, companies, orders, the catalogue, the intranet notes and the products of the current language),
 * the hits grouped by index. Every row comes from `with()` (Hit::$data): nothing is read from the database for it. The language is the
 * page's (`lang`); the intranet group follows the viewer of the Security page (the firewall covers this route too) and is left out for a
 * viewer who may not search it. The ERP page's Combined tab stays the comparison of one multi-source index with a federated search.
 */
final class MagicController extends AbstractController
{
    private const int MAX_QUERY = 100;

    /** Every index reads this many hits for the merged page; the floor keeps a small index from being crowded out. */
    private const int PAGE = 18;
    private const int FLOOR = 3;

    /** How many rows of an index the box shows; the link "all N" goes to the rest. */
    private const int SHOWN = 4;

    /** The federated search without the language index: index => weight. RRF ranks by position, so the weight is how much an index's hits count. */
    private const array WEIGHTS = ['people' => 1.0, 'companies' => 1.0, 'orders' => 0.8, 'catalog' => 1.0];

    #[Route('/magic', name: 'magic', methods: ['GET'])]
    public function __invoke(
        Request $request,
        Fuzzphony $fuzzphony,
        AuthorizationCheckerInterface $checker,
        #[Autowire(service: 'limiter.fuzzphony_magic')]
        RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $limit = $limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->json(['error' => sprintf('Too many searches: the demo allows a few a second. Try again in %d s.', $retry)], 429, ['Retry-After' => (string) $retry]);
        }
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
        $lang = (string) $request->query->get('lang', 'en');
        $lang = isset(Languages::LANGUAGES[$lang]) ? $lang : 'en';
        $viewer = $this->getUser();
        $viewer = $viewer instanceof DemoUser ? $viewer : null;
        $answer = ['q' => $q, 'lang' => $lang, 'viewer' => $viewer?->name, 'tookMs' => 0.0, 'groups' => []];
        if ($q === '') {
            return $this->json($answer);
        }

        $as = static fn (SearchBuilder $b): SearchBuilder => $b->asYouType()->with();
        $search = $fuzzphony->federated()->asSystem()->query($q)->limit(self::PAGE)->unlogged();
        foreach (self::WEIGHTS as $index => $weight) {
            $search = $search->index($index, $weight, $as, self::FLOOR);
        }
        // the intranet: a viewer who may search it, in the company and the fields the guards would allow (the Security page's explain() does the
        // same); asSystem() with that is what keeps the rate limit of the Security page (five searches in 10 seconds) out of a box that searches on every key
        if ($viewer !== null && $checker->isGranted('FUZZPHONY_SEARCH', 'intranet')) {
            $fields = [];
            foreach ($fuzzphony->definition('intranet')->fields as $field) {
                if ($field->granted === null || $checker->isGranted($field->granted, 'intranet')) {
                    $fields[] = $field->name;
                }
            }
            $company = (int) $viewer->getCompany();
            $search = $search->index('intranet', 0.9, static fn (SearchBuilder $b): SearchBuilder => $b->asYouType()->with()->forTenant($company)->restrictedToFields(...$fields), self::FLOOR);
        }
        $search = $search->index(Languages::LANGUAGES[$lang]['index'], 0.7, $as, self::FLOOR);

        try {
            $found = $search->get();
        } catch (FuzzphonyException $e) {
            return $this->json(['error' => $e->getMessage()] + $answer, 422);
        }

        $groups = [];
        foreach ($found->hits as $hit) {
            $groups[$hit->index] ??= ['index' => $hit->index, 'title' => Magic::title($hit->index), 'total' => $found->results[$hit->index]->total, 'lowerBound' => $found->results[$hit->index]->totalIsLowerBound, 'url' => Magic::indexUrl($hit->index, $q), 'rows' => []];
            if (count($groups[$hit->index]['rows']) < self::SHOWN) {
                $groups[$hit->index]['rows'][] = Magic::row($hit);
            }
        }
        foreach ($groups as &$group) {
            $group['url'] = $this->generateUrl(...$group['url']);
            foreach ($group['rows'] as &$row) {
                $row['url'] = $this->generateUrl(...$row['url']);
            }
            unset($row);
        }
        unset($group);

        return $this->json([...$answer, 'tookMs' => $found->tookMs, 'total' => $found->total, 'groups' => array_values($groups)]);
    }
}
