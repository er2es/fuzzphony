<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal The answer of the did-you-mean lookup (VocabularyService::suggest()): the query with the words it probably meant, the
 * statement that looked them up (for explain(); null when no lookup ran) and the query for the zero-result rescue (the typed query
 * with every word that has a vocabulary word exactly one edit away replaced by it; null unless each such word has exactly one).
 */
final readonly class Suggestion
{
    public function __construct(
        public ?string $didYouMean,
        public ?Statement $statement,
        public ?string $oneEdit,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null);
    }
}
