<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** A searchable text field of the document. */
final readonly class FieldDefinition
{
    public function __construct(
        public string $name,
        public Weight $weight = Weight::B,
        /** Also index this field for typo-tolerant (trigram) matching. */
        public bool $fuzzy = false,
        /** Allow highlighted snippets for this field. */
        public bool $highlight = true,
        /** Column of the source / document query; defaults to the field name. */
        public ?string $column = null,
    ) {}

    public function column(): string
    {
        return $this->column ?? $this->name;
    }
}
