<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Exception;

final class UnknownIndex extends \InvalidArgumentException implements FuzzphonyException
{
    /** @param list<string> $known */
    public function __construct(string $nameOrClass, array $known)
    {
        parent::__construct(sprintf(
            'No search index is registered for "%s". Registered indexes: %s. '
            . 'Add #[Searchable] to the entity or define the index under "fuzzphony.indexes".',
            $nameOrClass,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
