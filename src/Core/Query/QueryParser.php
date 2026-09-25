<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;

/**
 * Parses end-user search syntax into an AST. It NEVER throws: malformed input degrades
 * gracefully and every correction is reported as a warning.
 *
 *   wireless mouse          both words (AND is implicit)
 *   "wireless mouse"        phrase
 *   mouse OR trackpad       either (also: mouse | trackpad)
 *   -cable / NOT cable      exclude
 *   keyb*                   prefix
 *   brand:logitech          only in field "brand"
 *   (mouse OR trackpad) -cable
 */
final class QueryParser
{
    private const int MAX_DEPTH = 8;

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];
    private int $pos = 0;
    private int $depth = 0;
    private int $terms = 0;
    /** @var array<string, true> */
    private array $warnings = [];

    public function __construct(
        private readonly int $maxLength = 256,
        private readonly int $maxTerms = 16,
    ) {}

    public function parse(string $input): ParsedQuery
    {
        $this->pos = 0;
        $this->depth = 0;
        $this->terms = 0;
        $this->warnings = [];

        $input = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input));
        if (mb_strlen($input) > $this->maxLength) {
            $input = mb_substr($input, 0, $this->maxLength);
            $this->warn(sprintf('Search text was truncated to %d characters.', $this->maxLength));
        }
        $this->tokens = Lexer::tokenize($input);

        $parts = [];
        while ($this->peek() !== null) {
            $node = $this->parseOr();
            if ($node !== null) {
                $parts[] = $node;
            }
            if ($this->peek() === Lexer::RPAREN) {
                $this->warn('Ignored an unmatched ")".');
                ++$this->pos;
            }
        }

        return new ParsedQuery($this->combine(AllOf::class, $parts), array_keys($this->warnings));
    }

    private function parseOr(): ?Node
    {
        $nodes = [];
        $first = $this->parseAnd();
        if ($first !== null) {
            $nodes[] = $first;
        }
        while ($this->peek() === Lexer::OR) {
            ++$this->pos;
            $next = $this->parseAnd();
            if ($next === null) {
                $this->warn('Ignored an "OR" without a right-hand side.');
                continue;
            }
            $nodes[] = $next;
        }

        return $this->combine(AnyOf::class, $nodes);
    }

    private function parseAnd(): ?Node
    {
        $nodes = [];
        while (($type = $this->peek()) !== null && $type !== Lexer::RPAREN && $type !== Lexer::OR) {
            if ($type === Lexer::AND) {
                ++$this->pos;
                continue;
            }
            $node = $this->parseUnary();
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $this->combine(AllOf::class, $nodes);
    }

    /**
     * A chain of exclusions ("NOT -!x") is read in a loop, not recursively, so its length never
     * costs stack depth; only its parity matters (double negation cancels).
     */
    private function parseUnary(): ?Node
    {
        $negations = 0;
        while ($this->peek() === Lexer::NOT) {
            ++$this->pos;
            ++$negations;
        }
        if ($negations > self::MAX_DEPTH) {
            $this->warn('Repeated exclusions ("-" / NOT) were collapsed.');
        }

        $inner = $this->peek() === null ? null : $this->parsePrimary();
        if ($negations === 0) {
            return $inner;
        }
        if ($inner === null) {
            $this->warn('Ignored an exclusion ("-" / NOT) without a term.');

            return null;
        }
        if ($negations % 2 === 0) {
            return $inner;
        }

        return $inner instanceof Not ? $inner->node : new Not($inner);
    }

    private function parsePrimary(): ?Node
    {
        [$type, $value] = $this->tokens[$this->pos];
        ++$this->pos;

        switch ($type) {
            case Lexer::LPAREN:
                if ($this->depth >= self::MAX_DEPTH) {
                    $this->warn(sprintf('Parentheses nested deeper than %d were flattened.', self::MAX_DEPTH));

                    return null;
                }
                ++$this->depth;
                $inner = $this->parseOr();
                --$this->depth;
                if ($this->peek() === Lexer::RPAREN) {
                    ++$this->pos;
                } else {
                    $this->warn('Added a missing ")".');
                }

                return $inner;

            case Lexer::PHRASE:
                return $this->phrase($value);

            case Lexer::FIELD:
                $next = $this->peek();
                if ($next === Lexer::WORD || $next === Lexer::PHRASE) {
                    [$nextType, $nextValue] = $this->tokens[$this->pos];
                    ++$this->pos;
                    $inner = $nextType === Lexer::PHRASE ? $this->phrase($nextValue) : $this->term($nextValue);

                    return $inner instanceof Term || $inner instanceof Phrase ? new FieldScoped($value, $inner) : null;
                }

                return $this->term($value);

            case Lexer::WORD:
                return $this->term($value);

            default: // stray operator tokens (AND/OR at odd positions) are skipped
                return null;
        }
    }

    private function term(string $raw): ?Term
    {
        $prefix = str_ends_with($raw, '*');
        $text = trim($raw, '*');
        if (preg_match('/[\p{L}\p{N}]/u', $text) !== 1 || !$this->countTerm()) {
            return null;
        }

        return new Term($text, $prefix);
    }

    private function phrase(string $raw): Term|Phrase|null
    {
        $split = preg_split('/\s+/u', trim($raw));
        $words = array_values(array_filter(
            $split !== false ? $split : [],
            static fn(string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1,
        ));
        if ($words === []) {
            return null;
        }
        if (count($words) === 1) {
            return $this->term($words[0]);
        }
        if (!$this->countTerm()) {
            return null;
        }

        return new Phrase($words);
    }

    private function countTerm(): bool
    {
        if ($this->terms >= $this->maxTerms) {
            $this->warn(sprintf('Only the first %d terms were used.', $this->maxTerms));

            return false;
        }
        ++$this->terms;

        return true;
    }

    /**
     * @param class-string<AllOf|AnyOf> $class
     * @param list<Node>                $nodes
     */
    private function combine(string $class, array $nodes): ?Node
    {
        $flat = [];
        foreach ($nodes as $node) {
            array_push($flat, ...($node instanceof $class ? $node->nodes : [$node]));
        }

        return match (count($flat)) {
            0 => null,
            1 => $flat[0],
            default => new $class($flat),
        };
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos][0] ?? null;
    }

    private function warn(string $message): void
    {
        $this->warnings[$message] = true;
    }
}
