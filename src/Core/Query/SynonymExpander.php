<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * @internal Rewrites a parsed query with the synonyms of its index: a word (or quoted phrase) that is
 * a member of a group, or the source of a one-way rule, becomes `AnyOf(original, alternative, ...)`
 * (flagged as an expansion), so every engine's compiler already understands the result. The
 * alternatives are flagged as implied (Term::$synonym / Phrase::$synonym): they are not part of the
 * words the exact and prefix bonuses compare with, and the relaxation treats the whole expansion as
 * the one word the user typed.
 *
 * Words are compared by their stem (the engine asks the database for it: the index's text
 * configuration decides, accents folded); English leaves a plural abbreviation such as `tvs`
 * unstemmed, so list it as a member. A multi-word member matches only a quoted phrase; a prefix
 * (`tv*`) is never expanded; a synonym of a synonym is not followed. The table of an index is built
 * once (the engine keeps the expander); the work per search is the lookup of the query's words.
 */
final readonly class SynonymExpander
{
    /** @var array<string, list<non-empty-list<string>>> key of a word => the words of each alternative */
    private array $table;

    /**
     * @param array<string, string> $memberStems lowercase word of the synonyms => stem; a word that is missing stems to itself
     *
     * A prepared Synonyms (Fuzzphony::stemSynonyms()) carries its table: it is used as it is when these are its stems.
     */
    public function __construct(Synonyms $synonyms, private array $memberStems)
    {
        $this->table = $synonyms->table !== [] && $synonyms->stemConfig !== null && $memberStems === $synonyms->stems
            ? $synonyms->table
            : self::tableOf($synonyms, $memberStems);
    }

    /**
     * The lookup table of the synonyms for these member stems.
     *
     * @param array<string, string> $memberStems
     *
     * @return array<string, list<non-empty-list<string>>>
     */
    public static function tableOf(Synonyms $synonyms, array $memberStems): array
    {
        // each member is split and keyed once; the group's alternatives are then added by key
        /** @var array<string, array{words: list<string>, key: string}> $known */
        $known = [];
        /** @var array<string, array<string, non-empty-list<string>>> $table */
        $table = [];
        $pairs = [];
        foreach ($synonyms->groups as $group) {
            foreach ($group as $source) {
                foreach ($group as $alternative) {
                    $pairs[] = [$source, $alternative];
                }
            }
        }
        foreach ($synonyms->rules as $rule) {
            foreach ($rule['targets'] as $target) {
                $pairs[] = [$rule['source'], $target];
            }
        }
        foreach ($pairs as [$source, $alternative]) {
            $from = $known[$source] ??= self::keyed($source, $memberStems);
            $to = $known[$alternative] ??= self::keyed($alternative, $memberStems);
            $words = $to['words'];
            if ($from['key'] !== '' && $words !== [] && $to['key'] !== $from['key']) {
                $table[$from['key']][$to['key']] = $words;
            }
        }

        return array_map(array_values(...), $table);
    }

    /**
     * @param array<string, string> $memberStems
     *
     * @return array{words: list<string>, key: string}
     */
    private static function keyed(string $text, array $memberStems): array
    {
        $words = self::words($text);

        return ['words' => $words, 'key' => self::keyOf($words, [], $memberStems)];
    }

    /**
     * Every lowercase word of the synonyms: what the engine asks the database to stem once.
     *
     * @return list<string>
     */
    public static function wordsOf(Synonyms $synonyms): array
    {
        $words = [];
        foreach ($synonyms->groups as $group) {
            foreach ($group as $member) {
                array_push($words, ...self::words($member));
            }
        }
        foreach ($synonyms->rules as $rule) {
            array_push($words, ...self::words($rule['source']));
            foreach ($rule['targets'] as $target) {
                array_push($words, ...self::words($target));
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * The lowercase words of the leaves of a query that may be expanded.
     *
     * @return list<string>
     */
    public static function queryWords(Node $node): array
    {
        return match (true) {
            $node instanceof Term => $node->prefix || $node->synonym ? [] : [mb_strtolower($node->text)],
            $node instanceof Phrase => $node->synonym ? [] : array_map(mb_strtolower(...), $node->words),
            $node instanceof FieldScoped, $node instanceof Not => self::queryWords($node->node),
            $node instanceof AllOf, $node instanceof AnyOf => array_values(array_unique(array_merge(...array_map(self::queryWords(...), $node->nodes)))),
            default => [],
        };
    }

    /**
     * The lowercase words the user typed that the synonyms expanded: a word with such an alternative
     * is one the index knows by definition, so "did you mean" does not correct it.
     *
     * @return list<string>
     */
    public static function expandedWords(Node $expanded): array
    {
        return match (true) {
            $expanded instanceof AnyOf && $expanded->expansion => array_values(array_unique(array_merge(self::queryWords($expanded->nodes[0]), ...array_map(self::expandedWords(...), array_slice($expanded->nodes, 1))))),
            $expanded instanceof AllOf, $expanded instanceof AnyOf => array_values(array_unique(array_merge(...array_map(self::expandedWords(...), $expanded->nodes)))),
            $expanded instanceof Not => self::expandedWords($expanded->node),
            default => [],
        };
    }

    /**
     * @param array<string, string> $queryStems lowercase word of the query => stem
     * @param int                   $budget     the most alternatives to add; a word whose alternatives do not fit stays as typed
     * @param bool                  $truncated  set to true when a word was left as typed for that reason
     */
    public function expand(Node $node, array $queryStems = [], int $budget = PHP_INT_MAX, bool &$truncated = false): Node
    {
        return $this->walk($node, $queryStems, $budget, $truncated);
    }

    /** @param array<string, string> $stems */
    private function walk(Node $node, array $stems, int &$budget, bool &$truncated): Node
    {
        return match (true) {
            $node instanceof Term, $node instanceof Phrase => $this->leaf($node, $node, static fn(Term|Phrase $alternative): Node => $alternative, $stems, $budget, $truncated),
            $node instanceof FieldScoped => $this->leaf($node, $node->node, static fn(Term|Phrase $alternative): Node => new FieldScoped($node->field, $alternative), $stems, $budget, $truncated),
            $node instanceof Not => new Not($this->walk($node->node, $stems, $budget, $truncated)),
            $node instanceof AllOf => new AllOf($this->walkAll($node->nodes, $stems, $budget, $truncated)),
            $node instanceof AnyOf => new AnyOf($this->walkAll($node->nodes, $stems, $budget, $truncated), $node->expansion),
            default => $node,
        };
    }

    /**
     * @param list<Node>            $nodes
     * @param array<string, string> $stems
     *
     * @return list<Node>
     */
    private function walkAll(array $nodes, array $stems, int &$budget, bool &$truncated): array
    {
        $walked = [];
        foreach ($nodes as $child) {
            $walked[] = $this->walk($child, $stems, $budget, $truncated); // in order, one budget for the whole query
        }

        return $walked;
    }

    /**
     * @param \Closure(Term|Phrase): Node $wrap
     * @param array<string, string>       $stems
     */
    private function leaf(Node $original, Term|Phrase $word, \Closure $wrap, array $stems, int &$budget, bool &$truncated): Node
    {
        if (($word instanceof Term && ($word->prefix || $word->synonym)) || ($word instanceof Phrase && $word->synonym)) {
            return $original;
        }
        $words = $word instanceof Term ? [mb_strtolower($word->text)] : array_map(mb_strtolower(...), $word->words);
        $alternatives = $this->table[$this->key($words, $stems)] ?? [];
        if ($alternatives === []) {
            return $original;
        }
        if (count($alternatives) > $budget) {
            $truncated = true;

            return $original;
        }
        $budget -= count($alternatives);

        return new AnyOf([$original, ...array_map(
            static fn(array $alternative): Node => $wrap(count($alternative) === 1 ? new Term($alternative[0], false, true) : new Phrase($alternative, true)),
            $alternatives,
        )], expansion: true);
    }

    /**
     * @param list<string>          $words
     * @param array<string, string> $stems
     */
    private function key(array $words, array $stems): string
    {
        return self::keyOf($words, $stems, $this->memberStems);
    }

    /**
     * @param list<string>         $words
     * @param array<string, string> $stems
     * @param array<string, string> $memberStems
     */
    private static function keyOf(array $words, array $stems, array $memberStems): string
    {
        return implode(' ', array_map(static fn(string $word): string => $stems[$word] ?? $memberStems[$word] ?? $word, $words));
    }

    /**
     * The lowercase words of a member, split the way a query is.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        // the common member, plain words (letters and digits) separated by single spaces, needs no parser (a long
        // list has thousands of them). It gives the parser's words exactly: cut at the default length in characters,
        // an exact AND / OR / NOT is an operator (so the text goes to the parser), at most the default number of terms
        if (preg_match('/^[\p{L}\p{N}]+(?: [\p{L}\p{N}]+)*\z/u', $text) === 1) {
            if (strlen($text) > Thresholds::DEFAULT_MAX_QUERY_LENGTH && mb_strlen($text) > Thresholds::DEFAULT_MAX_QUERY_LENGTH) {
                $text = rtrim(mb_substr($text, 0, Thresholds::DEFAULT_MAX_QUERY_LENGTH));
            }
            if (!str_contains($text, ' ')) {
                if ($text !== 'AND' && $text !== 'OR' && $text !== 'NOT') {
                    return [mb_strtolower($text)];
                }
            } else {
                $words = explode(' ', $text);
                if (array_intersect($words, ['AND', 'OR', 'NOT']) === []) {
                    return array_map(mb_strtolower(...), array_slice($words, 0, QueryParser::DEFAULT_MAX_TERMS));
                }
            }
        }

        return array_map(mb_strtolower(...), NodeInspector::positiveWords((new QueryParser())->parse($text)->root));
    }
}
