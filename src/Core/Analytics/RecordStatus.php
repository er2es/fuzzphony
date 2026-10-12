<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

/** What became of a search: it ran, or it was refused before it ran. */
enum RecordStatus: string
{
    case Ok = 'ok';
    case Denied = 'denied';
    case RateLimited = 'rate_limited';
}
