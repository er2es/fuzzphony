<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Engine\Postgres\Statement;

/** @internal The facets of a search by facet name (in the order the query asked for them) and the statements that counted them. */
final readonly class FacetResult
{
    /**
     * @param array<string, list<FacetValue>> $facets
     * @param list<Statement>                 $statements
     */
    public function __construct(public array $facets, public array $statements) {}
}
