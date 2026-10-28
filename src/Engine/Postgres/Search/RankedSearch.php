<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Query\Ast\Node;

/**
 * @internal What the ranked statement of a search was built from: the exact total and the facets of the search rebuild the same
 * selection from it.
 */
final readonly class RankedSearch
{
    /**
     * @param list<string>                    $emptyQueries leaf tsqueries the text configuration reduces to nothing (stop words)
     * @param array<string, list<int|string>> $candidates   the ids each probed trigram word matches, by candidate key
     */
    public function __construct(
        public ?string $tsquery,
        public string $plain,
        public ?Node $fuzzyRoot,
        public array $emptyQueries,
        public ?Node $scopedRoot,
        public array $candidates,
    ) {}
}
