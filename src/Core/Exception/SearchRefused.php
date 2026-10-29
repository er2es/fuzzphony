<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/**
 * A search that Fuzzphony refused on purpose: AccessDenied (not granted), ActorRequired (no tenant) and
 * RateLimited (too often). The HTTP layer shows them as 403 and 429. An interface, not a base class, so each
 * keeps its parent (ActorRequired is still an InvalidQuery, the other two a RuntimeException).
 */
interface SearchRefused extends FuzzphonyException {}
