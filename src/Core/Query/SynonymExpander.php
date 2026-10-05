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
    /** @var array<string, list<list<string>>> key of a word => the words of each alternative */
    private array $table;

    /** @param array<string, string> $memberStems lowercase word of the synonyms => stem; a word that is missing stems to itself */
    public function __construct(Synonyms $synonyms, private array $memberStems)
    {
        // each member is split once; the group's alternatives are then added by key
        $cache = [];
        $split = static function (string $text) use (&$cache): array {
            return $cache[$text] ??= self::words($text);
        };
        $table = [];
        $add = function (string $source, string $alternative) use (&$table, $split): void {
            $sourceWords = $split($source);
            $alternativeWords = $split($alternative);
            $key = $this->key($sourceWords, []);
            if ($key === '' || $alternativeWords === [] || $this->key($alternativeWords, []) === $key) {
                return;
            }
            $table[$key][$this->key($alternativeWords, [])] = $alternativeWords;
        };
        foreach ($synonyms->groups as $group) {
            foreach ($group as $member) {
                foreach ($group as $other) {
                    $add($member, $other);
                }
            }
        }
        foreach ($synonyms->rules as $rule) {
            foreach ($rule['targets'] as $target) {
                $add($rule['source'], $target);
            }
        }
        $this->table = array_map(array_values(...), $table);
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
        return implode(' ', array_map(fn(string $word): string => $stems[$word] ?? $this->memberStems[$word] ?? $word, $words));
    }

    /**
     * The lowercase words of a member, split the way a query is.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_map(mb_strtolower(...), NodeInspector::positiveWords((new QueryParser())->parse($text)->root));
    }
}
