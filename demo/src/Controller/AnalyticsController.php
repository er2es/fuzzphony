<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DemoUser;
use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What was searched in the catalogue during the last seven days, and who searched what (the latest searches of the catalogue
 * and the intranet notes), from the search log (fuzzphony.search_log).
 */
final class AnalyticsController extends AbstractController
{
    /** The indexes whose log the "Who searched what" list shows: `catalog` is searched by anybody, `intranet` by the Security page's viewers. */
    private const array INDEXES = ['catalog', 'intranet'];
    private const int RECENT = 20;

    #[Route('/under-the-hood/analytics', name: 'analytics')]
    public function analytics(Request $request, Fuzzphony $fuzzphony): Response
    {
        $from = new \DateTimeImmutable('-7 days');
        $top = $zero = [];
        $error = false;

        try {
            $top = $fuzzphony->analytics()->topQueries('catalog', $from, null, 15);
            $zero = $fuzzphony->analytics()->zeroResultQueries('catalog', $from, null, 15);
        } catch (FuzzphonyException) {
            // A demo database from before 0.9 has no search log table: say how to create it instead of failing.
            $error = true;
        }

        // The filters of the list are links (?index=&who=&status=); anything that is not on the lists falls back to "all".
        $viewers = ['anonymous' => 'Anonymous'] + array_map(static fn(DemoUser $u): string => $u->name, DemoUser::all());
        $index = $this->choice($request, 'index', self::INDEXES);
        $who = $this->choice($request, 'who', array_keys($viewers));
        $status = $this->choice($request, 'status', array_map(static fn(RecordStatus $s): string => $s->value, RecordStatus::cases()));

        $recent = [];
        $recentError = false;
        if (!$error) {
            try {
                $recent = $this->recent($fuzzphony, $from, $index, $who, $status);
            } catch (FuzzphonyException) {
                // A demo database from before 0.9.6 has no actor_ip column: the same hint as above.
                $recentError = true;
            }
        }

        return $this->render('analytics.html.twig', [
            'top' => $top,
            'zero' => $zero,
            'error' => $error,
            'recent' => $recent,
            'recentError' => $recentError,
            'filters' => [
                'index' => ['all' => 'All', 'catalog' => 'Catalogue', 'intranet' => 'Intranet'],
                'who' => ['all' => 'Everyone'] + $viewers,
                'status' => ['all' => 'All', 'ok' => 'Answered', 'denied' => 'Denied', 'rate_limited' => 'Rate limited'],
            ],
            'chosen' => ['index' => $index ?? 'all', 'who' => $who ?? 'all', 'status' => $status ?? 'all'],
            'userNames' => array_map(static fn(DemoUser $u): string => $u->name, DemoUser::all()),
            'companies' => ['1' => 'Acme', '2' => 'Globex'],
            'limit' => self::RECENT,
        ]);
    }

    /**
     * The newest searches of the chosen index (or of both), newest first. The list is a showcase for every viewer, so it reads across
     * the tenants: the `intranet` index is tenant-scoped, and a report over a tenant-scoped index must say `allTenants: true`
     * on purpose. A real application would put this page behind an operator role (docs/analytics.md, "Privacy").
     *
     * @return list<LoggedSearch>
     */
    private function recent(Fuzzphony $fuzzphony, \DateTimeImmutable $from, ?string $index, ?string $who, ?string $status): array
    {
        $rows = [];
        foreach ($index !== null ? [$index] : self::INDEXES as $name) {
            $rows = [...$rows, ...$fuzzphony->analytics()->recentSearches(
                $name,
                $from,
                limit: self::RECENT,
                allTenants: true,
                user: $who !== null && $who !== 'anonymous' ? $who : null,
                anonymousOnly: $who === 'anonymous',
                status: $status !== null ? RecordStatus::from($status) : null,
            )];
        }
        // each list is newest first already; the sort is stable, so two searches of one second keep their order
        usort($rows, static fn(LoggedSearch $a, LoggedSearch $b): int => $b->at <=> $a->at);

        return array_slice($rows, 0, self::RECENT);
    }

    /**
     * @param list<string> $allowed
     *
     * @return string|null the value of the query parameter when it is one of $allowed ("all" and anything else is null: no filter)
     */
    private function choice(Request $request, string $name, array $allowed): ?string
    {
        $value = (string) $request->query->get($name, 'all');

        return in_array($value, $allowed, true) ? $value : null;
    }
}
