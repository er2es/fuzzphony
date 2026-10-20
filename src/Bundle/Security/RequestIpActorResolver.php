<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal Wraps the configured actor resolver (fuzzphony.search_log.ip is not off): an actor that carries no IP gets
 * the client address of the request. Request::getClientIp() follows framework.trusted_proxies; X-Forwarded-For is never
 * read here. An actor the application built with an IP of its own keeps it. Whether and how much of it is stored is
 * decided by the search gate (IpMode).
 */
final readonly class RequestIpActorResolver implements ActorResolver
{
    public function __construct(private ActorResolver $inner, private RequestStack $requests) {}

    public function resolve(): Actor
    {
        $actor = $this->inner->resolve();
        $ip = $actor->ip === null ? $this->requests->getMainRequest()?->getClientIp() : null;

        return $ip === null ? $actor : new Actor($actor->tenant, $actor->user, $ip);
    }
}
