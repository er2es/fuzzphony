<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Sql;

/** @internal Collects bound parameters; each placeholder is unique (native prepares cannot reuse names). */
final class ParameterBag
{
    /** @var array<string, scalar|null> */
    private array $params = [];

    public function add(bool|int|float|string|null $value): string
    {
        $name = 'p' . count($this->params);
        $this->params[$name] = $value;

        return ':' . $name;
    }

    /** @return array<string, scalar|null> */
    public function all(): array
    {
        return $this->params;
    }
}
