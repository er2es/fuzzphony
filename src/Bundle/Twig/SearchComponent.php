<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Twig;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Search-as-you-type without writing JavaScript:
 *
 *   <twig:Fuzzphony:Search index="products" highlight="name" placeholder="Search products…" />
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

    private ?SearchResult $result = null;

    public function __construct(private readonly Fuzzphony $fuzzphony) {}

    public function getResult(): ?SearchResult
    {
        if (trim($this->query) === '') {
            return null;
        }
        $fields = array_values(array_filter(array_map('trim', explode(',', $this->highlight)), static fn(string $f): bool => $f !== ''));

        return $this->result ??= $this->fuzzphony->in($this->index)
            ->query($this->query)
            ->profile($this->profile)
            ->highlight(...$fields)
            ->limit(max(1, min(50, $this->limit)))
            ->get();
    }
}
