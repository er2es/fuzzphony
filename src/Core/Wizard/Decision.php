<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

/** One explained wizard decision, e.g. "name -> field A, fuzzy: looks like a title". */
final readonly class Decision
{
    public function __construct(
        public string $column,
        public string $role,
        public string $reason,
    ) {}
}
