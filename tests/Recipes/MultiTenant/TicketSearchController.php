<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\Hit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

// region: controller
final class TicketSearchController
{
    public function __construct(private readonly Fuzzphony $fuzzphony, private readonly ActorResolver $actors) {}

    #[Route('/tickets/search')]
    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->fuzzphony->in('tickets')
            ->asActor($this->actors->resolve())          // the tenant and the user come from the Security token, never from the request
            ->query(trim((string) $request->query->get('q', '')))
            ->highlight('subject')
            ->limit(20)
            ->get();

        // an ActorRequired or AccessDenied is answered with 403, a RateLimited with 429 and Retry-After, by the bundle's listener
        return new JsonResponse([
            'total' => $result->total,
            'hits' => array_map(static fn(Hit $hit): array => ['id' => $hit->id, 'subject' => $hit->highlights['subject'] ?? null], $result->hits),
        ]);
    }
}
// endregion
