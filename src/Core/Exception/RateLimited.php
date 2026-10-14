<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

/** The actor searched too often; $retryAfter is the number of seconds to wait (the HTTP layer shows 429). */
final class RateLimited extends \RuntimeException implements FuzzphonyException
{
    public function __construct(public readonly int $retryAfter, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Too many searches: try again in %d seconds.', $retryAfter), 0, $previous);
    }
}
