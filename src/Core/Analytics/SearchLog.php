<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** Where search records go. */
interface SearchLog
{
    public function record(SearchRecord $record): void;
}
