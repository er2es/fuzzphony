<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DemoUser;
use App\Service\Examples;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who is searching decides what a search may see: the worked example of docs/security.md. The visitor picks one of five
 * fixed viewers (ViewerAuthenticator); the search names no actor, so it is made as the current Security user and the configured guards run: the company is the
 * tenant, ROLE_USER may search the `intranet` index (SearchVoter), the `confidential` field needs ROLE_HR, and a viewer
 * may search five times in 10 seconds. A refusal is an answer of the page, never an error page.
 */
final class SecurityController extends AbstractController
{
    private const string INDEX = 'intranet';
    private const int LIMIT = 5;
    private const int MAX_QUERY = 100;

    #[Route('/under-the-hood/security', name: 'security')]
    public function page(
        Request $request,
        Fuzzphony $fuzzphony,
        ActorResolver $actors,
        AuthorizationCheckerInterface $checker,
        Connection $connection,
        #[Autowire(service: 'limiter.fuzzphony_search')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        $user = $this->getUser();
        $viewer = $user instanceof DemoUser ? $user : null;
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY);

        // Demo only: forget what this viewer has searched in the current 10 seconds (the flows and a second try need it).
        // It redirects (and leaves a one-time notice in the session) so that a reload or the back button does not reset again.
        if ($viewer !== null && $request->query->getBoolean('reset') && $request->hasSession()) {
            $limiter->create($viewer->getUserIdentifier())->reset();
            $request->getSession()->getFlashBag()->add('reset', $viewer->name);

            return $this->redirectToRoute('security', ['as' => $viewer->getUserIdentifier(), 'q' => $q !== '' ? $q : null], Response::HTTP_SEE_OTHER);
        }
        $wasReset = $request->hasPreviousSession() && $request->hasSession() ? $request->getSession()->getFlashBag()->get('reset') !== [] : false;

        // The facts of this viewer, computed from the same checks the guards make.
        $definition = $fuzzphony->registry()->get(self::INDEX);
        $allowed = $forbidden = [];
        if ($viewer !== null && $checker->isGranted('FUZZPHONY_SEARCH', self::INDEX)) {
            foreach ($definition->fields as $field) {
                if ($field->granted === null || $checker->isGranted($field->granted, self::INDEX)) {
                    $allowed[] = $field->name;
                } else {
                    $forbidden[$field->name] = $field->granted;
                }
            }
        }

        $result = $explanation = $outcome = null;
        $rows = [];
        if ($q !== '') {
            try {
                // The search itself: the guards run, the rate limit is charged once, and the search log gets one row
                // (the viewer as the user, the company as the tenant, the masked client IP; a refusal is a `denied` or
                // `rate_limited` row). explain() would run the same search but records no row for an answered one.
                // No asActor(): the search is made as the application's current user (the token), which is the point of the
                // "compare the calls" table below. The resolver is asked here only for the tenant of the explain().
                $actor = $actors->resolve();
                $result = $fuzzphony->in(self::INDEX)
                    ->query($q)
                    ->highlight('title', 'body', 'confidential')
                    ->limit(20)
                    ->get();
                // Its SQL, from a system search: no guard runs (a guarded one would charge the rate limit a second time) and nothing is
                // logged (the typed text would be a second row), so it is given what the guards decided: the company and the fields.
                $explanation = $fuzzphony->in(self::INDEX)
                    ->asSystem()
                    ->forTenant($actor->tenant)
                    ->restrictedToFields(...$allowed)
                    ->query($q)
                    ->highlight('title', 'body', 'confidential')
                    ->limit(20)
                    ->explain();
                $rows = $this->rows($connection, array_map(static fn($hit) => $hit->id, $result->hits));
            } catch (AccessDenied $e) {
                $outcome = ['kind' => 'denied', 'class' => 'AccessDenied', 'message' => $e->getMessage()];
            } catch (RateLimited $e) {
                $outcome = ['kind' => 'limited', 'class' => 'RateLimited', 'message' => $e->getMessage(), 'retryAfter' => $e->retryAfter];
            } catch (ActorRequired $e) {
                $outcome = ['kind' => 'actor', 'class' => 'ActorRequired', 'message' => $e->getMessage()];
            } catch (FuzzphonyException $e) {
                $outcome = ['kind' => 'error', 'class' => (new \ReflectionClass($e))->getShortName(), 'message' => $e->getMessage()];
            }
        }

        $compare = null;
        if ($q !== '' && $request->query->getBoolean('compare')) {
            $compare = $this->compare($fuzzphony, $q, $viewer, $result, $outcome);
        }

        $used = null;
        if ($viewer !== null) {
            $used = self::LIMIT - $limiter->create($viewer->getUserIdentifier())->consume(0)->getRemainingTokens();
        }

        return $this->render('security.html.twig', [
            'viewers' => array_map(static fn(DemoUser $u) => $u->name, DemoUser::all()) + [DemoUser::ANONYMOUS => 'Anonymous'], // nobody last: the page opens on Ada
            'viewer' => $viewer,
            'viewerId' => $viewer?->getUserIdentifier() ?? DemoUser::ANONYMOUS,
            'q' => $q,
            'examples' => Examples::INTRANET,
            'result' => $result,
            'explanation' => $explanation,
            'rows' => $rows,
            'outcome' => $outcome,
            'compare' => $compare,
            'allowed' => $allowed,
            'forbidden' => $forbidden,
            'used' => $used,
            'limit' => self::LIMIT,
            'wasReset' => $wasReset,
            'companies' => DemoUser::COMPANIES,
        ]);
    }

    /**
     * The same words searched by the four kinds of call (docs/security.md, "Who is searching"): what a controller sees that names no actor
     * (the page's own search above), one that names Ada explicitly, one that runs where nobody can be resolved (a command, a worker) and one
     * that says asSystem(). None is logged here (the page's own search is); the guards of the first three run for real.
     *
     * @param array{kind: string, class: string, message: string}|null $outcome the refusal of the page's own search, if any
     *
     * @return list<array{label: string, code: string, ran: string, hits: ?int, confidential: ?int, refusal: ?string}>
     */
    private function compare(Fuzzphony $fuzzphony, string $q, ?DemoUser $viewer, ?SearchResult $own, ?array $outcome): array
    {
        $tenant = $viewer?->getCompany() ?? 1;
        $run = static function (callable $make) use ($q): array {
            try {
                /** @var SearchResult $r */
                $r = $make()->query($q)->highlight('title', 'body', 'confidential')->limit(20)->unlogged()->get();

                return [$r->total, count(array_filter($r->hits, static fn($h): bool => isset($h->highlights['confidential']))), null];
            } catch (FuzzphonyException $e) {
                return [null, null, (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage()];
            }
        };
        $row = static fn(string $label, string $code, string $ran, array $r): array => ['label' => $label, 'code' => $code, 'ran' => $ran, 'hits' => $r[0], 'confidential' => $r[1], 'refusal' => $r[2]];
        $ownRun = $own !== null
            ? [$own->total, count(array_filter($own->hits, static fn($h): bool => isset($h->highlights['confidential']))), null]
            : [null, null, $outcome !== null ? $outcome['class'] . ': ' . $outcome['message'] : null];

        return [
            $row('No asActor()', "\$fuzzphony->in('intranet')->query(\$q)->get()", $viewer !== null ? $viewer->name . ' (the token user), company ' . $viewer->companyName : 'nobody: no token', $ownRun),
            $row('asActor(Ada)', "->asActor(new Actor(tenant: 1, user: 'ada'))", 'Ada, company Acme; the roles still come from the token', $run(fn() => $fuzzphony->in(self::INDEX)->asActor(new Actor(tenant: 1, user: 'ada')))),
            $row('asActor(anonymous)', '->asActor(Actor::anonymous())', 'what a command, a worker or a test sees: nobody, no company', $run(fn() => $fuzzphony->in(self::INDEX)->asActor(Actor::anonymous()))),
            $row('asSystem()', '->asSystem()->forTenant(' . $tenant . ')', 'no guard: every field, company ' . (DemoUser::COMPANIES[$tenant] ?? $tenant) . ' (a tenant must still be named)', $run(fn() => $fuzzphony->in(self::INDEX)->asSystem()->forTenant($tenant))),
        ];
    }

    /**
     * The note rows (company and plain text, never the confidential column) of the ids the search returned.
     *
     * @param list<int|string> $ids
     *
     * @return array<int|string, array{id: int, company_id: int, title: string, body: string}>
     */
    private function rows(Connection $connection, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<array{id: int, company_id: int, title: string, body: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            'SELECT id, company_id, title, body FROM demo_note WHERE id IN (:ids)',
            ['ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_column($rows, null, 'id');
    }
}
