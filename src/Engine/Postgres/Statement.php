<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

/**
 * @internal One statement of a search as the engine runs and explain() lists it. `readsWordThreshold` is declared by the builder
 * that compiled it (it holds a `<%` comparison): the session puts the user's word-similarity setting back before a statement that
 * reads it and set nothing itself (SearchSession).
 */
final readonly class Statement
{
    /** @param array<string, scalar|null> $params */
    public function __construct(
        public StatementKind $kind,
        public string $label,
        public string $sql,
        public array $params,
        public bool $readsWordThreshold = false,
    ) {}

    /** @param array{sql: string, params: array<string, scalar|null>, readsWordThreshold?: bool} $built what a builder returned */
    public static function of(StatementKind $kind, string $label, array $built): self
    {
        return new self($kind, $label, $built['sql'], $built['params'], $built['readsWordThreshold'] ?? false);
    }

    public function labelled(string $prefix): self
    {
        return new self($this->kind, $prefix . $this->label, $this->sql, $this->params, $this->readsWordThreshold);
    }

    /** @return array{label: string, sql: string, params: array<string, scalar|null>} as Explanation lists it */
    public function toArray(): array
    {
        return ['label' => $this->label, 'sql' => $this->sql, 'params' => $this->params];
    }
}
