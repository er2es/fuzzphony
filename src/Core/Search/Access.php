<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

/** What a SearchGuard lets an actor search in an index: every field (fields = null) or only the listed ones. */
final readonly class Access
{
    /** @param list<string>|null $fields */
    private function __construct(public ?array $fields) {}

    public static function all(): self
    {
        return new self(null);
    }

    /** @param list<string> $fields */
    public static function only(array $fields): self
    {
        return new self(array_values(array_unique($fields)));
    }

    public function isAll(): bool
    {
        return $this->fields === null;
    }

    /** What both allow. */
    public function intersect(self $other): self
    {
        return match (true) {
            $this->fields === null => $other,
            $other->fields === null => $this,
            default => new self(array_values(array_intersect($this->fields, $other->fields))),
        };
    }
}
