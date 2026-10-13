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
 * may search five times a minute. A refusal is an answer of the page, never an error page.
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

        // Demo only: forget what this viewer has searched this minute (the flows and a second try need it).
        $wasReset = false;
        if ($viewer !== null && $request->query->getBoolean('reset')) {
            $limiter->create($viewer->getUserIdentifier())->reset();
            $wasReset = true;
        }

        $result = $explanation = $outcome = null;
        $rows = [];
        if ($q !== '') {
            try {
                // One explain() is the search and its SQL together, and charges the rate limit once.
                $explanation = $fuzzphony->in(self::INDEX)
                    ->asActor($actors->resolve())
                    ->query($q)
                    ->highlight('title', 'body', 'confidential')
                    ->limit(20)
                    ->explain();
                $result = $explanation->result;
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
