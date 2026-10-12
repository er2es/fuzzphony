<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Analytics\SearchLog;
use Fuzzphony\Core\Analytics\SearchRecord;

/** @internal Collects every record, in order, for assertion. */
final class RecordingSearchLog implements SearchLog
{
    /** @var list<SearchRecord> */
    public array $records = [];

    public function record(SearchRecord $record): void
    {
        $this->records[] = $record;
    }
}
