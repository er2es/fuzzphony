<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** How the several values of a many field enter the text rank: all together as one text (0.11), or through the best single value. */
enum ManyRank: string
{
    case Whole = 'whole';
    case Best = 'best';
}
