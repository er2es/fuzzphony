<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Erp;
use App\Service\ErpSync;
use Doctrine\DBAL\Exception as DbalException;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ERP data set (sql/erp.sql): three admin-style search boxes, one for each of people, companies and orders (every row comes from
 * `with()`, the page loads nothing from the database; the link goes to a small detail page), a combined box that shows the same
 * query in the multi-source index `site` and in a federated search over the three, and the sync demonstration.
 */
final class ErpController extends AbstractController
{
    private const int MAX_QUERY = 100;

    private const array MODES = ['both' => 'Side by side', 'site' => 'One multi-source index', 'federated' => 'Federated search'];

    /** The federated search of the combined box: index => [weight, floor]. */
    public const array FEDERATION = ['people' => [1.0, 2], 'companies' => [1.2, 2], 'orders' => [0.8, 1]];

    #[Route('/erp', name: 'erp')]
    public function __invoke(Request $request, Fuzzphony $fuzzphony, ErpSync $sync): Response
    {
        $tab = (string) $request->query->get('tab', 'people');
        if (!isset(Erp::TABS[$tab]) && !in_array($tab, ['combined', 'sync'], true)) {
            throw $this->createNotFoundException('Unknown tab.');
        }
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);
        $live = $request->headers->get('X-Requested-With') === 'fetch';
        $data = ['tab' => $tab, 'q' => $q, 'tabs' => Erp::TABS, 'error' => null, 'result' => null, 'hits' => [], 'site' => null, 'federated' => null, 'mode' => 'both'];

        try {
            if (isset(Erp::TABS[$tab])) {
                $data = [...$data, ...$this->single($request, $fuzzphony, $tab, $q)];
            } elseif ($tab === 'combined') {
                $mode = (string) $request->query->get('mode', 'both');
                $data['mode'] = isset(self::MODES[$mode]) ? $mode : 'both';
                $data = [...$data, ...$this->combined($fuzzphony, $q, $data['mode']), 'modes' => self::MODES, 'federation' => self::FEDERATION, 'examples' => Erp::COMBINED_EXAMPLES];
            }
        } catch (FuzzphonyException $e) {
            $data['error'] = $e->getMessage();
        }

        if ($tab === 'sync') {
            $data = [...$data, ...$this->syncData($request, $sync)];
        }

        // the live search asks for the results region only
        if ($live && $tab !== 'sync') {
            return $this->render('erp/_region.html.twig', $data);
        }

        return $this->render('erp/index.html.twig', $data);
    }

    /**
     * One admin box: its own index, its own filters and facet. Rows come from `with()`; the lines under them from `explainMatches()`.
     *
     * @return array<string, mixed>
     */
    private function single(Request $request, Fuzzphony $fuzzphony, string $tab, string $q): array
    {
        $index = Erp::TABS[$tab]['index'];
        // People: "One address must match all words" needs the best-address ranking, so it takes it along; the ranking alone is the default
        $single = $tab === 'people' && $request->query->get('single') === '1';
        $best = $tab === 'people' && ($single || $request->query->get('best', '1') !== '0');
        if ($tab === 'people' && !$best) {
            $index = Erp::PEOPLE_WHOLE;
        }
        $city = mb_substr(trim((string) $request->query->get('city', '')), 0, 60);
        $status = mb_substr(trim((string) $request->query->get('status', '')), 0, 20);
        $created = (string) $request->query->get('created', '');
        $total = (string) $request->query->get('total', '');
        $created = isset(Erp::CREATED[$created]) ? $created : '';
        $total = isset(Erp::TOTALS[$total]) ? $total : '';
        $filters = ['city' => $tab === 'people' ? $city : '', 'status' => $tab === 'orders' ? $status : '', 'created' => $tab === 'orders' ? '' : $created, 'total' => $tab === 'orders' ? $total : ''];

        $facets = [];
        $result = null;
        if ($q !== '') {
            $search = $fuzzphony->in($index)->asSystem()->query($q)->asYouType()->with()->explainMatches()->highlight(...Erp::HIGHLIGHTED[$tab])->limit(15)->unlogged();
            if ($single) {
                $search = $search->manyMatch('single');
            }
            if ($tab === 'people') {
                $search = $search->facets('city');
                if ($filters['city'] !== '') {
                    $search = $search->where('city', $filters['city']);
                }
            } elseif ($tab === 'orders') {
                $search = $search->facets('status');
                if ($filters['status'] !== '') {
                    $search = $search->where('status', $filters['status']);
                }
                if ($filters['total'] !== '') {
                    $search = $search->whereBetween('total', Erp::TOTALS[$filters['total']][1], Erp::TOTALS[$filters['total']][2]);
                }
            }
            if ($filters['created'] !== '') {
                $search = $search->whereBetween('created_at', Erp::CREATED[$filters['created']][1], Erp::CREATED[$filters['created']][2]);
            }
            $result = $search->get();
            $facets = $result->facets[$tab === 'people' ? 'city' : 'status'] ?? [];
        }

        return ['result' => $result, 'hits' => $result->hits ?? [], 'facets' => $facets, 'filters' => $filters, 'best' => $best, 'single' => $single, 'created_options' => Erp::CREATED, 'total_options' => Erp::TOTALS, 'examples' => Erp::TABS[$tab]['examples']];
    }

    /**
     * The same text in the multi-source index `site` and in a federated search over people, companies and orders.
     *
     * @return array{site: \Fuzzphony\Core\Search\SearchResult|null, federated: \Fuzzphony\Core\Search\FederatedResult|null}
     */
    private function combined(Fuzzphony $fuzzphony, string $q, string $mode): array
    {
        $site = $federated = null;
        if ($q !== '') {
            if ($mode !== 'federated') {
                $site = $fuzzphony->in('site')->asSystem()->query($q)->asYouType()->with()->explainMatches()->limit(12)->unlogged()->get();
            }
            if ($mode !== 'site') {
                $search = $fuzzphony->federated()->asSystem()->query($q)->limit(12)->unlogged();
                foreach (self::FEDERATION as $index => [$weight, $floor]) {
                    $search = $search->index($index, $weight, static fn (SearchBuilder $b): SearchBuilder => $b->asYouType()->with()->explainMatches(), $floor);
                }
                $federated = $search->get();
            }
        }

        return ['site' => $site, 'federated' => $federated];
    }

    /** @return array<string, mixed> */
    private function syncData(Request $request, ErpSync $sync): array
    {
        $address = $sync->address();
        $session = $request->getSession();
        $state = $session->get('erp_sync');
        $status = $address !== null && is_array($state) ? $sync->status($state, $address['customer']) : null;
        $flash = $session->getFlashBag()->get('erp_sync');

        return [
            'address' => $address,
            'cities' => ErpSync::CITIES,
            'original' => ErpSync::ORIGINAL,
            'status' => $status,
            'triggers' => $sync->triggers(),
            'flash' => $flash[0] ?? null,
        ];
    }

    /** The panel only (polled by sync_controller.js while the worker has not refreshed the indexes yet). */
    #[Route('/erp/sync/status', name: 'erp_sync_status', methods: ['GET'])]
    public function syncStatus(Request $request, ErpSync $sync): Response
    {
        $address = $sync->address();
        $state = $request->getSession()->get('erp_sync');
        $status = $address !== null && is_array($state) ? $sync->status($state, $address['customer']) : null;

        return $this->render('erp/_sync_status.html.twig', ['address' => $address, 'status' => $status]);
    }

    /**
     * The one write of the demo: POST, a CSRF token, five moves a minute for each address of a visitor, only erp_address #1, only its
     * city, only to a city of the fixed list (or back to the original). The database role can do no more (UPDATE (city) on erp_address).
     */
    #[Route('/erp/sync', name: 'erp_sync', methods: ['POST'])]
    public function move(
        Request $request,
        ErpSync $sync,
        #[Autowire(service: 'limiter.fuzzphony_erp_sync')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        if (!$this->isCsrfTokenValid('erp_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $to = (string) $request->request->get('to', '');
        $city = $to === 'undo' ? ErpSync::ORIGINAL : (ErpSync::CITIES[$to] ?? null);
        if ($city === null) {
            return new Response('Unknown city.', Response::HTTP_BAD_REQUEST);
        }
        $session = $request->getSession();
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            $session->getFlashBag()->add('erp_sync', 'Too many moves: the demo allows a few a minute. Wait a moment and try again.');

            return $this->redirectToRoute('erp', ['tab' => 'sync'], Response::HTTP_SEE_OTHER);
        }
        $address = $sync->address();
        if ($address === null) {
            $session->getFlashBag()->add('erp_sync', 'There is no ERP data in this demo (it was started with DEMO_ERP=0).');

            return $this->redirectToRoute('erp', ['tab' => 'sync'], Response::HTTP_SEE_OTHER);
        }
        try {
            $session->set('erp_sync', $sync->move($city, $address['customer']));
        } catch (DbalException) {
            $session->getFlashBag()->add('erp_sync', 'The change could not be written: this database user may not update the address.');
        }

        return $this->redirectToRoute('erp', ['tab' => 'sync'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/erp/{kind}/{id}', name: 'erp_detail', requirements: ['kind' => 'people|people_whole|person|companies|company|orders|order', 'id' => '\d+'], methods: ['GET'])]
    public function detail(string $kind, int $id, Erp $erp): Response
    {
        if (Erp::tableOf($kind) === 'order') {
            $order = $erp->order($id) ?? throw $this->createNotFoundException('No such order.');

            return $this->render('erp/detail.html.twig', ['what' => 'order', 'row' => $order, 'lines' => $erp->lines($id), 'kind' => $kind]);
        }
        $customer = $erp->customer($id) ?? throw $this->createNotFoundException('No such customer.');

        return $this->render('erp/detail.html.twig', [
            'what' => 'customer', 'row' => $customer, 'kind' => $kind,
            'addresses' => $erp->addresses($id), 'contacts' => $erp->contacts($id), 'orders' => $erp->ordersOf($id),
        ]);
    }
}
