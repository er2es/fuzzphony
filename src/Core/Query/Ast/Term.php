<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

final readonly class Term implements Node
{
    public function __construct(
        public string $text,
        /** "keyb*" matches keyboard, keyboards, ... */
        public bool $prefix = false,
    ) {}

    public function __toString(): string
    {
        return $this->text . ($this->prefix ? '*' : '');
    }
}
