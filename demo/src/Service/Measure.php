<?php

declare(strict_types=1);

namespace App\Service;

/** Timing the fair way: the first run is reported separately ("cold"), then the median of N warm runs. */
final class Measure
{
    /**
     * @template T
     *
     * @param callable(): T $run
     *
     * @return array{cold: float, warm: float, value: T}
     */
    public static function median(callable $run, int $runs = 5): array
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
