<?php

declare(strict_types=1);

namespace App\Service;

/** Timing the fair way: the first run is reported separately ("cold"), then the median of N warm runs. */
final class Measure
{
    /** The default warm-run count of {@see median()}, also used by the pages to explain their own timings. */
    public const int WARM_RUNS = 5;

    /**
     * @template T
     *
     * @param callable(): T $run
     *
     * @return array{cold: float, warm: float, value: T}
     */
    public static function median(callable $run, int $runs = self::WARM_RUNS): array
    {
        $t = hrtime(true);
        $value = $run();
        $cold = (hrtime(true) - $t) / 1e6;

        $times = [];
        for ($i = 0; $i < $runs; ++$i) {
            $t = hrtime(true);
            $value = $run();
            $times[] = (hrtime(true) - $t) / 1e6;
        }
        sort($times);

        return ['cold' => $cold, 'warm' => $times[intdiv(count($times), 2)], 'value' => $value];
    }
}
