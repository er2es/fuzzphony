<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** Tells who is searching right now (the current request's user, a console option, ...). Never null: no one is Actor::anonymous(). */
interface ActorResolver
{
    public function resolve(): Actor;
}
