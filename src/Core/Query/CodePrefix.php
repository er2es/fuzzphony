<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Ranking\Thresholds;

/**
 * @internal The code-field prefix rescue: a `code: true` field indexes `ORD-2025-000030` as `ord`, `2025`, `000030` and
 * `ord2025000030`, so `ORD-2025-00003` (a beginning that stops inside a part) is no whole word anywhere. A search that
 * found nothing is searched once more with its last word as a prefix on the code fields the viewer may search:
 *
 *   ORD-2025-00003          ->  number:ORD-2025-00003*
 *   kovacs ORD202500003     ->  kovacs (number:ORD202500003* | tax_no:ORD202500003*)
 *   number:ORD-2025-00003   ->  number:ORD-2025-00003*
 *
 * Never for a search-as-you-type text (its last word is a prefix already), with `rescue.correct_when_empty: false`, when the
 * last word is excluded, in a phrase, a prefix already, or scoped to a field that is not a code. Only the fields the viewer
 * may search (SearchQuery::$allowedFields) are used, so a hidden code field changes nothing.
 */
final class CodePrefix
{
    /** @return array{from: string, to: string}|null the typed query and the rescue query, both as the parser reads them */
    public static function rescue(IndexDefinition $index, SearchQuery $query, Thresholds $thresholds): ?array
    {
        if ($query->asYouType || !$thresholds->rescue->correctWhenEmpty) {
            return null;
        }
        $codes = [];
        foreach ($index->fields as $field) {
            if ($field->code && ($query->allowedFields === null || in_array($field->name, $query->allowedFields, true))) {
                $codes[] = $field->name;
            }
        }
        if ($codes === []) {
            return null;
        }
        $parser = new QueryParser($thresholds->limits->maxQueryLength, $thresholds->limits->maxTerms);
        $root = $parser->parse($query->text)->root;
        if ($root === null) {
            return null;
        }
        $nodes = $root instanceof AllOf ? $root->nodes : [$root];
        $last = array_key_last($nodes);
        $node = $nodes[$last];
        if ($node instanceof FieldScoped && in_array($node->field, $codes, true)) {
            $codes = [$node->field];
            $node = $node->node;
        }
        if (!$node instanceof Term || $node->prefix || preg_match('/[\p{L}\p{N}]/u', $node->text) !== 1) {
            return null;
        }
        $prefix = new Term($node->text, prefix: true);
        $scoped = array_map(static fn(string $code): Node => new FieldScoped($code, $prefix), $codes);
        $nodes[$last] = count($scoped) === 1 ? $scoped[0] : new AnyOf($scoped);
        $rescued = count($nodes) === 1 ? $nodes[0] : new AllOf($nodes);
        $to = QueryRenderer::render($rescued);

        // a word the parser would read differently once it ends in "*" (quoted syntax: "a:b") is no rescue
        return (string) $parser->parse($to)->root === (string) $rescued ? ['from' => QueryRenderer::render($root), 'to' => $to] : null;
    }
}
