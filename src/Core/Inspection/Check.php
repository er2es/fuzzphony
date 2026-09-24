<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Inspection;

final readonly class Check
{
    public function __construct(
        public string $name,
        public CheckStatus $status,
        public string $message,
        /** A copy-pasteable fix (SQL or command), when there is one. */
        public ?string $fix = null,
    ) {}

    public static function ok(string $name, string $message): self
    {
        return new self($name, CheckStatus::Ok, $message);
    }

    public static function warning(string $name, string $message, ?string $fix = null): self
    {
        return new self($name, CheckStatus::Warning, $message, $fix);
    }

    public static function error(string $name, string $message, ?string $fix = null): self
    {
        return new self($name, CheckStatus::Error, $message, $fix);
    }

    public static function skipped(string $name, string $message): self
    {
        return new self($name, CheckStatus::Skipped, $message);
    }
}
