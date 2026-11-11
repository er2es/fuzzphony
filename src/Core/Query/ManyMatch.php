<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

/** What a search asks of a many field with `rank: best`: any value mix (the default) or one value that holds every word needed from the field. */
enum ManyMatch: string
{
    case Any = 'any';
    case Single = 'single';
}
