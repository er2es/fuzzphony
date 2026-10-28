<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/** @internal What a statement of a search is for; explain() and the metrics read this, never the label text. */
enum StatementKind
{
    /** A statement that selects the page: browse, full text, full text with typo tolerance. */
    case Search;
    /** A page statement that ran as the fallback of a strict one that found too few hits. */
    case Fallback;
    /** The empty-result relaxation probe. */
    case Probe;
    /** The did-you-mean lookup. */
    case Suggest;
    /** The exact total of a capped search. */
    case ExactTotal;
    /** The facets of one group. */
    case Facet;

    /** Whether the statement is one of those that select the hits (what explain() shows the plan of). */
    public function isSearch(): bool
    {
        return $this === self::Search || $this === self::Fallback;
    }
}
