<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\IpLimit;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\ActorResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** @internal An anonymous public search box: the actor comes from the bundle's resolver, which carries the client IP. */
final class IpLimitController
{
    public function __construct(private readonly Fuzzphony $fuzzphony, private readonly ActorResolver $actors) {}

    #[Route('/search')]
    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->fuzzphony->in('items')
            ->asActor($this->actors->resolve())
            ->query(trim((string) $request->query->get('q', '')))
            ->limit(5)
            ->get();

        return new JsonResponse(['total' => $result->total]);
    }

    /** The same search without naming an actor: the library sees no request, so nothing can limit it. */
    #[Route('/plain')]
    public function plain(Request $request): JsonResponse
    {
        return new JsonResponse(['total' => $this->fuzzphony->in('items')->query(trim((string) $request->query->get('q', '')))->limit(5)->get()->total]);
    }
}
