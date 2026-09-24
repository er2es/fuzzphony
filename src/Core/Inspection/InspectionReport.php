<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Inspection;

final readonly class InspectionReport
{
    /** @param list<Check> $checks */
    public function __construct(
        public string $index,
        public array $checks,
    ) {}

    public function status(): CheckStatus
    {
        $statuses = array_map(static fn (Check $c): CheckStatus => $c->status, $this->checks);

        return match (true) {
            in_array(CheckStatus::Error, $statuses, true) => CheckStatus::Error,
            in_array(CheckStatus::Warning, $statuses, true) => CheckStatus::Warning,
            default => CheckStatus::Ok,
        };
    }

    public function isHealthy(): bool
    {
        return $this->status() !== CheckStatus::Error;
    }

    /** @return list<Check> */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (Check $c): bool => $c->status === CheckStatus::Error || $c->status === CheckStatus::Warning,
        ));
    }
}
