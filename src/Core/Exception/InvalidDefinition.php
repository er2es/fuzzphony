<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

final class InvalidDefinition extends \InvalidArgumentException implements FuzzphonyException
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly string $index,
        public readonly array $violations,
    ) {
        parent::__construct(sprintf(
            "Search index \"%s\" is misconfigured:\n  - %s",
            $index,
            implode("\n  - ", $violations),
        ));
    }
}
