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
 * a member of a group, or the source of a one-way rule, becomes `AnyOf(original, alternative, ...)`,
 * so every engine's compiler already understands the result. The alternatives are flagged as implied
 * (Term::$synonym / Phrase::$synonym): they are not part of the words the exact and prefix bonuses
 * compare with, and the relaxation warning does not name them.
 *
 * Words are compared by their stem (the engine asks the database for it: the index's text
 * configuration decides, accents folded), so `TVs` finds the group of `tv`. A multi-word member
 * matches only a quoted phrase; a prefix (`tv*`) is never expanded; a synonym of a synonym is not
 * followed.
 */
final readonly class SynonymExpander
{
    /** @var array<string, list<list<string>>> key of a word => the words of each alternative */
    private array $table;

    /** @param array<string, string> $stems lowercase word => stem; a word that is missing stems to itself */
    public function __construct(Synonyms $synonyms, private array $stems)
    {
        $table = [];
        $add = function (string $source, string $alternative) use (&$table): void {
            $key = $this->key(self::words($source));
            $words = self::words($alternative);
            if ($key === '' || $words === [] || $this->key($words) === $key) {
                return;
            }
            $table[$key][$this->key($words)] = $words;
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
     * Every lowercase word of the synonyms: what the engine asks the database to stem, together with
     * queryWords().
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
            $node instanceof FieldScoped => self::queryWords($node->node),
            $node instanceof Not => self::queryWords($node->node),
            $node instanceof AllOf, $node instanceof AnyOf => array_values(array_unique(array_merge(...array_map(self::queryWords(...), $node->nodes)))),
            default => [],
        };
    }

    public function expand(Node $node): Node
    {
        return match (true) {
            $node instanceof Term, $node instanceof Phrase => $this->leaf($node, $node, static fn(Term|Phrase $alternative): Node => $alternative),
            $node instanceof FieldScoped => $this->leaf($node, $node->node, static fn(Term|Phrase $alternative): Node => new FieldScoped($node->field, $alternative)),
            $node instanceof Not => new Not($this->expand($node->node)),
            $node instanceof AllOf => new AllOf(array_map($this->expand(...), $node->nodes)),
            $node instanceof AnyOf => new AnyOf(array_map($this->expand(...), $node->nodes)),
            default => $node,
        };
    }

    /**
     * @param \Closure(Term|Phrase): Node $wrap
     */
    private function leaf(Node $original, Term|Phrase $word, \Closure $wrap): Node
    {
        if (($word instanceof Term && ($word->prefix || $word->synonym)) || ($word instanceof Phrase && $word->synonym)) {
            return $original;
        }
        $words = $word instanceof Term ? [mb_strtolower($word->text)] : array_map(mb_strtolower(...), $word->words);
        $alternatives = $this->table[$this->key($words)] ?? [];
        if ($alternatives === []) {
            return $original;
        }

        return new AnyOf([$original, ...array_map(
            static fn(array $alternative): Node => $wrap(count($alternative) === 1 ? new Term($alternative[0], false, true) : new Phrase($alternative, true)),
            $alternatives,
        )]);
    }

    /** @param list<string> $words */
    private function key(array $words): string
    {
        return implode(' ', array_map(fn(string $word): string => $this->stems[$word] ?? $word, $words));
    }

    /**
     * The lowercase words of a member, split the way a query is, so `wi-fi` is the same two words on both sides.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_map(mb_strtolower(...), NodeInspector::positiveWords((new QueryParser())->parse($text)->root));
    }
}
