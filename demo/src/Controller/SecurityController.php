<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DemoUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who is searching decides what a search may see: the worked example of docs/security.md. The visitor picks one of five
 * fixed viewers (ViewerAuthenticator); the search is made with `asActor()`, so the configured guards run: the company is the
 * tenant, ROLE_USER may search the `intranet` index (SearchVoter), the `confidential` field needs ROLE_HR, and a viewer
 * may search five times in 10 seconds. A refusal is an answer of the page, never an error page.
 */
final class SecurityController extends AbstractController
{
    private const string INDEX = 'intranet';
    private const int LIMIT = 5;
    private const int MAX_QUERY = 100;

    /** label => query */
    private const array EXAMPLES = [
        'only in a hidden field' => 'salary',
        'in both companies' => 'budget',
        'also only in a hidden field' => 'lawsuit',
        'in a title and a hidden field' => 'bonus',
        'ordinary' => 'menu',
    ];

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
                $actor = $actors->resolve();
                $result = $fuzzphony->in(self::INDEX)
                    ->asActor($actor)
                    ->query($q)
                    ->highlight('title', 'body', 'confidential')
                    ->limit(20)
                    ->get();
                // Its SQL, from a search that is not an actor search: no guard runs (nothing is charged twice) and nothing is
                // logged (the typed text would be a second row), so it is given what the guards decided: the company and the fields.
                $explanation = $fuzzphony->in(self::INDEX)
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

        $used = null;
        if ($viewer !== null) {
            $used = self::LIMIT - $limiter->create($viewer->getUserIdentifier())->consume(0)->getRemainingTokens();
        }

        return $this->render('security.html.twig', [
            'viewers' => ['anonymous' => 'Anonymous'] + array_map(static fn(DemoUser $u) => $u->name, DemoUser::all()),
            'viewer' => $viewer,
            'viewerId' => $viewer?->getUserIdentifier() ?? DemoUser::ANONYMOUS,
            'q' => $q,
            'examples' => self::EXAMPLES,
            'result' => $result,
            'explanation' => $explanation,
            'rows' => $rows,
            'outcome' => $outcome,
            'allowed' => $allowed,
            'forbidden' => $forbidden,
            'used' => $used,
            'limit' => self::LIMIT,
            'wasReset' => $wasReset,
            'companies' => [1 => 'Acme', 2 => 'Globex'],
        ]);
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
