<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Ast;

/** name:mouse or name:"wireless mouse" — restricts matching to one field. */
final readonly class FieldScoped implements Node
{
    public function __construct(
        public string $field,
        public Term|Phrase $node,
    ) {}

    public function __toString(): string
    {
        return $this->field . ':' . $this->node;
    }
}
