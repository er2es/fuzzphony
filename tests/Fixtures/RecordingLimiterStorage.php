<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** A rate-limiter storage that keeps nothing but records what a cache pool would have been given. */
final class RecordingLimiterStorage implements StorageInterface
{
    /** @var list<string> the ids of the saved states */
    public array $saved = [];

    /** @var list<string> the serialised states */
    public array $states = [];

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->saved[] = $limiterState->getId();
        $this->states[] = serialize($limiterState);
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        return null;
    }

    public function delete(string $limiterStateId): void {}
}
