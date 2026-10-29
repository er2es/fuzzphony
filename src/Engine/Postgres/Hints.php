<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/** @internal The remedies the engine appends to an EngineFailure, written once. */
final class Hints
{
    /** A search statement, the synonyms' stems, the stop-word lookup, the highlights, the plan: the index is what to look at. */
    public const string INDEX = 'Run "bin/console fuzzphony:doctor" to check the index.';
    public const string REBUILD = 'Run "fuzzphony:schema --apply" and "fuzzphony:doctor".';
    public const string SCHEMA_AND_DOCTOR = 'Run "fuzzphony:schema --apply" and check "fuzzphony:doctor".';
    public const string SEARCH_LOG = 'Run "fuzzphony:schema --apply" (the log must be enabled) and "fuzzphony:doctor"; the role needs INSERT, SELECT and DELETE on the search log table.';

    private function __construct() {}
}
