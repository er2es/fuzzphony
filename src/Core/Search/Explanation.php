<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** Everything needed to understand (and reproduce) one search. */
final readonly class Explanation
{
    /**
     * @param list<array{label: string, sql: string, params: array<string, scalar|null>}> $statements
     * @param list<string>                                                                 $plan
     */
    public function __construct(
        public string $interpretedAs,
        public array $statements,
        public array $plan,
        public SearchResult $result,
    ) {}
}
