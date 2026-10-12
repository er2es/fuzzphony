<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;

/** @internal The actor of an application that has none configured: nobody. */
final readonly class NullActorResolver implements ActorResolver
{
    public function resolve(): Actor
    {
        return Actor::anonymous();
    }
}
