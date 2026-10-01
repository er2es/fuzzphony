<?php

declare(strict_types=1);

namespace App\Service;

/** Symfony's plain logger ignores the context, so the metric values are written into the line here. */
final class MetricLogFormatter
{
    /** @param array<array-key, mixed> $context */
    public static function format(string $level, string $message, array $context): string
    {
        $pairs = [];
        foreach ($context as $key => $value) {
            $pairs[] = $key . '=' . (is_scalar($value) ? (string) $value : json_encode($value));
        }

        return sprintf('%s [%s] %s %s', date('c'), $level, $message, implode(' ', $pairs)) . PHP_EOL;
    }
}
