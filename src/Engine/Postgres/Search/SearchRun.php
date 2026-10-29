<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Search;

use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Engine\Postgres\Statement;

/**
 * @internal One search, answered: the result, the statements it ran, the setting the last of them needed (explain() runs it again)
 * and, for the first run of a search that found nothing, the corrected query the zero-result rescue may run.
 */
final readonly class SearchRun
{
    /**
     * @param list<Statement>                       $statements
     * @param array{from: string, to: string}|null $rescue      the typed query and the corrected one, both as the parser reads them
     * @param bool                                  $relaxed     whether the answer is the empty-result relaxation's (a word dropped)
     */
    public function __construct(
        public SearchResult $result,
        public array $statements,
        public ?float $threshold,
        public ?array $rescue = null,
        public bool $relaxed = false,
    ) {}

    /** A search that ran no statement (nothing to look for). */
    public static function empty(SearchResult $result): self
    {
        return new self($result, [], null);
    }
}
