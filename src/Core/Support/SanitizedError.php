<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/**
 * @internal What stays of a driver exception once its message (which can carry the values of the source row) is gone:
 * the class and the SQLSTATE, so ErrorSummary still tells data errors from schema errors along the chain. The message of a
 * structural failure (SQLSTATE class 42: a missing column, table or privilege) names identifiers only and is kept.
 */
final class SanitizedError extends \RuntimeException
{
    public function __construct(public readonly string $originalClass, private readonly ?string $sqlState, string $structural = '')
    {
        parent::__construct($structural !== '' ? $structural : $originalClass . ($sqlState === null ? '' : ' (SQLSTATE ' . $sqlState . ')'));
    }

    public static function of(\Throwable $e): self
    {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }
        $state = ErrorSummary::sqlState($e);

        return new self($root instanceof self ? $root->originalClass : $root::class, $state, $state !== null && str_starts_with($state, '42') ? $root->getMessage() : '');
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
