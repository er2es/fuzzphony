<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** Language analysis: stemming language + optional accent folding ("cafe" finds "café"). */
final readonly class TextConfig
{
    public function __construct(
        /** A PostgreSQL text search configuration to copy: english, hungarian, german, simple, ... */
        public string $language = 'english',
        public bool $unaccent = true,
    ) {}

    /** Name of the text search configuration used by the index. */
    public function configName(): string
    {
        return $this->unaccent ? 'fuzzphony_' . $this->language : $this->language;
    }
}
