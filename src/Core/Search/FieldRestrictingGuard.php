<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/**
 * Marks a SearchGuard that can return Access::only([...]): one that decides which fields an actor may see (an authorization guard). A field with
 * `granted` is hidden only by such a guard; Fuzzphony::postgres() refuses to build with a granted field and no guard of this kind, because a
 * rate limit alone returns Access::all() and would show the field to everybody.
 */
interface FieldRestrictingGuard extends SearchGuard {}
