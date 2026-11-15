<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Support;

/**
 * @internal What stays of a driver exception once its message (which can carry the values of the source row) is gone:
 * the class and the SQLSTATE, so ErrorSummary still tells data errors from schema errors along the chain.
 */
final class SanitizedError extends \RuntimeException
{
    public function __construct(public readonly string $originalClass, private readonly ?string $sqlState)
    {
        parent::__construct($originalClass . ($sqlState === null ? '' : ' (SQLSTATE ' . $sqlState . ')'));
    }

    public static function of(\Throwable $e): self
    {
        $root = $e;
        while ($root->getPrevious() !== null) {
            $root = $root->getPrevious();
        }

        return new self($root instanceof self ? $root->originalClass : $root::class, ErrorSummary::sqlState($e));
    }

    public function getSQLState(): ?string
    {
        return $this->sqlState;
    }
}
