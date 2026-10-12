<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Analytics;

use Fuzzphony\Core\Exception\InvalidConfiguration;

/** Replaces whatever matches one of the regular expressions with a placeholder. */
final readonly class PatternQueryRedactor implements QueryRedactor
{
    public const string EMAIL = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u';

    /**
     * @param list<string> $patterns regular expressions with delimiters
     *
     * @throws InvalidConfiguration for a pattern that does not compile
     */
    public function __construct(private array $patterns, private string $replacement = '[redacted]')
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new InvalidConfiguration(sprintf('search_log.redact: "%s" is not a valid regular expression.', $pattern));
            }
        }
    }

    /** @param list<string> $settings "email" or a regular expression with delimiters */
    public static function fromSettings(array $settings): self
    {
        return new self(array_map(static fn(string $s): string => $s === 'email' ? self::EMAIL : $s, $settings));
    }

    public function redact(string $query): string
    {
        foreach ($this->patterns as $pattern) {
            $query = (string) preg_replace($pattern, $this->replacement, $query);
        }

        return $query;
    }
}
