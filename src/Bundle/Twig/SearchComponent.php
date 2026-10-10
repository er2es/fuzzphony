<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Twig;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Search-as-you-type without writing JavaScript:
 *
 *   <twig:Fuzzphony:Search index="products" highlight="name" placeholder="Search products…" />
 *   <twig:Fuzzphony:Search index="products" facets="category_id,in_stock" suggestions="5" />
 *
 * While the visitor types, the word being typed is completed from the index's vocabulary
 * (`suggestions`, 0 switches it off), and `facets` lists the values of those filters with their counts;
 * choosing one narrows the search (choosing it again lifts it).
 *
 * Override the look by copying templates/components/Search.html.twig to
 * templates/bundles/FuzzphonyBundle/components/Search.html.twig.
 */
#[AsLiveComponent('Fuzzphony:Search', template: '@Fuzzphony/components/Search.html.twig')]
final class SearchComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $query = '';

    #[LiveProp]
    public string $index = '';

    /** Comma-separated field names to highlight. */
    #[LiveProp]
    public string $highlight = '';

    #[LiveProp]
    public string $profile = 'default';

    #[LiveProp]
    public int $limit = 10;

    #[LiveProp]
    public string $placeholder = 'Search…';

    /** Comma-separated filter names whose values are listed with their counts (string, int, bool and date filters). */
    #[LiveProp]
    public string $facets = '';

    /** How many completions of the word being typed to offer; 0 switches them off. */
    #[LiveProp]
    public int $suggestions = 5;

    /**
     * The facet values chosen so far: filter => value as text ("" is the documents without a value).
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $selected = [];

    private ?SearchResult $result = null;

    public function __construct(private readonly Fuzzphony $fuzzphony) {}

    /** "Did you mean ...?": searches the suggestion. */
    #[LiveAction]
    public function useSuggestion(): void
    {
        $suggestion = $this->getResult()?->didYouMean;
        if ($suggestion !== null) {
            $this->query = $suggestion;
            $this->result = null;
        }
    }

    /** A completion of the word being typed: it becomes the query. */
    #[LiveAction]
    public function useCompletion(#[LiveArg] string $text): void
    {
        $this->query = $text;
        $this->result = null;
    }

    /** Chooses a facet value, or lifts the choice when it is the chosen one already. Only the filters listed in `facets` can be chosen. */
    #[LiveAction]
    public function toggleFacet(#[LiveArg] string $filter, #[LiveArg] string $value): void
    {
        if (!in_array($filter, $this->facetNames(), true)) {
            return;
        }
        if (($this->selected[$filter] ?? null) === $value) {
            unset($this->selected[$filter]);
        } else {
            $this->selected[$filter] = $value;
        }
        $this->result = null;
    }

    public function getResult(): ?SearchResult
    {
        if (trim($this->query) === '') {
            return null;
        }
        $fields = array_values(array_filter(array_map('trim', explode(',', $this->highlight)), static fn(string $f): bool => $f !== ''));

        $search = $this->fuzzphony->in($this->index)
            ->query($this->query)
            ->profile($this->profile)
            ->highlight(...$fields)
            ->limit(max(1, min(50, $this->limit)));
        $names = $this->facetNames();
        if ($names !== []) {
            $search = $this->narrow($search, $names)->facets(...$names);
        }

        return $this->result ??= $search->get();
    }

    /** @return list<string> the completions of the word being typed, as whole search texts */
    public function getCompletions(): array
    {
        return $this->suggestions > 0 && trim($this->query) !== '' ? $this->fuzzphony->suggest($this->index, $this->query, min(20, $this->suggestions)) : [];
    }

    /** @return list<string> */
    private function facetNames(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->facets)), static fn(string $f): bool => $f !== ''));
    }

    /** @param list<string> $names */
    private function narrow(SearchBuilder $search, array $names): SearchBuilder
    {
        $index = $this->fuzzphony->registry()->get($this->index);
        foreach ($this->selected as $filter => $value) {
            if (!in_array($filter, $names, true)) {
                continue; // a chosen value of a filter that is no longer a facet
            }
            $type = $index->filter($filter)->type;
            $search = match (true) {
                $value === '' => $search->whereNull($filter),
                $type === FilterType::Bool => $search->where($filter, $value === '1' || $value === 'true'),
                $type === FilterType::Int => ctype_digit(ltrim($value, '-')) && $value !== '-' ? $search->where($filter, (int) $value) : $search,
                default => $search->where($filter, $value),
            };
        }

        return $search;
    }
}
