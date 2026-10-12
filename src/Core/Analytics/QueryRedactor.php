<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** Removes what must not be stored from a search text before it is logged. */
interface QueryRedactor
{
    public function redact(string $query): string;
}
