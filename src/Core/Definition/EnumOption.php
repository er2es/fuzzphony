<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;

/** @internal Parses a developer-supplied enum value (builder strings, YAML) and names the allowed values on a typo. */
final class EnumOption
{
    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function parse(string $enum, string $value, string $index, string $what, string $context = ''): \BackedEnum
    {
        /** @var T|null $case */
        $case = $enum::tryFrom($value);
        if ($case === null) {
            throw new InvalidDefinition($index, [sprintf(
                'Unknown %s "%s"%s. Allowed: %s.',
                $what,
                $value,
                $context,
                implode(', ', array_column($enum::cases(), 'value')),
            )]);
        }

        return $case;
    }
}
