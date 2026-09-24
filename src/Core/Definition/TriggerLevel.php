<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** Granularity of database sync triggers (queue and trigger sync modes). */
enum TriggerLevel: string
{
    /**
     * One trigger call per statement with transition tables (default): a bulk UPDATE of
     * 100 000 rows enqueues all ids with a single INSERT ... SELECT instead of 100 000 calls.
     */
    case Statement = 'statement';
    /** One call per changed row: simpler, fine for low write volumes. */
    case Row = 'row';
}
