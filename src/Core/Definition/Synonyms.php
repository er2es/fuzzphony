<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;

/**
 * Synonyms of one index, expanded on the query side (no reindex). Two forms, in YAML, the builder and
 * the attribute alike:
 *
 *   ['tv', 'television']              a group: every member finds every other member
 *   'laptop => notebook | portable'   a one-way rule: "laptop" also finds "notebook" and "portable", not the reverse
 *
 * A member may be a phrase ('solid state drive'); it matches a quoted phrase in the query.
 */
final readonly class Synonyms
{
    /**
     * @param list<list<string>>                                      $groups
     * @param list<array{source: string, targets: list<string>}> $rules
     */
    public function __construct(
        public array $groups = [],
        public array $rules = [],
    ) {}

    /** The most members of a group, and targets of a rule: a bigger one is almost certainly a mistake and makes every query expensive. */
    public const int MAX_MEMBERS = 32;

    /**
     * @param array<array-key, mixed> $entries a list of groups (lists of strings) and rules ("a => b | c")
     * @param string                  $index   the index the entries belong to, for the error message
     */
    public static function fromEntries(array $entries, string $index = 'synonyms'): self
    {
        $groups = [];
        $rules = [];
        $problems = [];
        foreach ($entries as $i => $entry) {
            if (is_array($entry) && array_is_list($entry) && array_all($entry, static fn(mixed $member): bool => is_string($member))) {
                /** @var list<string> $entry */
                $groups[] = array_map(static fn(string $member): string => trim($member), $entry);
            } elseif (is_string($entry) && substr_count($entry, '=>') === 1) {
                [$source, $targets] = explode('=>', $entry, 2);
                $rules[] = ['source' => trim($source), 'targets' => array_values(array_filter(array_map(static fn(string $t): string => trim($t), explode('|', $targets)), static fn(string $t): bool => $t !== ''))];
            } else {
                $problems[] = sprintf('Synonym entry %s must be a list of words (a group) or a string with one "=>" such as "word => other | another" (a one-way rule).', is_int($i) ? (string) ($i + 1) : '"' . $i . '"');
            }
        }
        if ($problems !== []) {
            throw new InvalidDefinition($index, $problems);
        }

        return new self($groups, $rules);
    }

    /**
     * The entries this was built from, for the exporters.
     *
     * @return list<list<string>|string>
     */
    public function toEntries(): array
    {
        $entries = $this->groups;
        foreach ($this->rules as $rule) {
            $entries[] = $rule['source'] . ' => ' . implode(' | ', $rule['targets']);
        }

        return $entries;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [] && $this->rules === [];
    }

    /**
     * What is wrong with these synonyms, all of it.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $v = [];
        $word = static fn(string $text): bool => preg_match('/[\p{L}\p{N}]/u', $text) === 1;
        // a member is split like a query is, so query syntax in it would be read, not matched
        $plain = static fn(string $text): bool => preg_match('/["*:()|!]|(^|\s)-|\b(AND|OR|NOT)\b/u', $text) !== 1 && substr_count(trim($text), ' ') < 16;
        $key = static fn(string $text): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
        $seen = [];
        foreach ($this->groups as $i => $group) {
            $label = sprintf('Synonym group %d (%s)', $i + 1, implode(', ', $group));
            if (count(array_unique(array_map($key, $group))) < 2) {
                $v[] = $label . ' needs at least two different members.';
            }
            if (count($group) > self::MAX_MEMBERS) {
                $v[] = sprintf('%s has more than %d members.', $label, self::MAX_MEMBERS);
            }
            foreach ($group as $member) {
                if (!$word($member)) {
                    $v[] = sprintf('%s has a member without a letter or digit: "%s".', $label, $member);
                } elseif (!$plain($member)) {
                    $v[] = sprintf('%s has a member that is not plain words (no quotes, operators, "*" or ":", at most 16 words): "%s".', $label, $member);
                } elseif (isset($seen[$key($member)]) && $seen[$key($member)] !== $i) {
                    $v[] = sprintf('"%s" is in more than one synonym group; merge them.', $member);
                }
                $seen[$key($member)] = $i;
            }
        }
        $sources = [];
        foreach ($this->rules as $rule) {
            if (!$word($rule['source'])) {
                $v[] = sprintf('A synonym rule needs a word before "=>" (got "%s").', $rule['source']);
            } elseif (isset($sources[$key($rule['source'])])) {
                $v[] = sprintf('Synonym rule for "%s" is defined twice; list its targets together: "a => b | c".', $rule['source']);
            }
            $sources[$key($rule['source'])] = true;
            if ($rule['targets'] === [] || !array_all($rule['targets'], $word)) {
                $v[] = sprintf('The synonym rule for "%s" needs at least one target with a letter or digit.', $rule['source']);
            }
            if (count($rule['targets']) > self::MAX_MEMBERS) {
                $v[] = sprintf('The synonym rule for "%s" has more than %d targets.', $rule['source'], self::MAX_MEMBERS);
            }
            foreach ([$rule['source'], ...$rule['targets']] as $member) {
                if ($word($member) && !$plain($member)) {
                    $v[] = sprintf('The synonym rule for "%s" has a word that is not plain words (no quotes, operators, "*" or ":", at most 16 words): "%s".', $rule['source'], $member);
                }
            }
        }

        return $v;
    }
}
