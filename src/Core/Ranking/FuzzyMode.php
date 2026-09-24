<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Ranking;

enum FuzzyMode: string
{
    /** Always blend typo-tolerant matches into the results. */
    case Always = 'always';
    /** Only when exact full-text matching found fewer than Thresholds::$fallbackBelow hits (cheapest). */
    case Fallback = 'fallback';
    /** Never use trigram matching. */
    case Never = 'never';
}
