<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** @internal The log that keeps nothing: the default when search logging is off. */
final class NullSearchLog implements SearchLog
{
    public function record(SearchRecord $record): void {}
}
