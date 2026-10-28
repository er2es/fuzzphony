<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Exception\EngineFailure;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Observability\MetricsCollector;

/** @internal Times an operation, counts its errors and turns a database failure into an EngineFailure with a remedy. */
final readonly class EngineGuard
{
    public function __construct(private MetricsCollector $metrics) {}

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(string $name, callable $operation, string $hint): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
            $this->metrics->observe('fuzzphony.' . $name . '.duration_ms', round((hrtime(true) - $started) / 1e6, 3));

            return $result;
        } catch (FuzzphonyException $e) {
            $this->metrics->increment('fuzzphony.' . $name . '.errors');
            throw $e;
        } catch (\Throwable $e) {
            $this->metrics->increment('fuzzphony.' . $name . '.errors');
            throw EngineFailure::wrap($name, $e, $hint);
        }
    }

    public function metrics(): MetricsCollector
    {
        return $this->metrics;
    }
}
