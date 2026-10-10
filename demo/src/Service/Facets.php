<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Core\Search\SearchBuilder;

/**
 * The facets of the catalogue pages (Compare, Playground): the category and the stock. The benchmark catalogue has
 * fifty category rows that share ten names, so the facet over `category_id` (fifty values) is merged by name here, and
 * choosing a name searches all the ids it stands for (`whereIn`): what an application does when a facet value needs a label.
 */
final class Facets
{
    /** @var array<string, list<int>>|null category name => the ids with that name */
    private ?array $ids = null;
    /** @var array<int, string>|null */
    private ?array $names = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * The category facet of a result as names with counts, the most frequent first.
     *
     * @param list<FacetValue> $values the values of the `category_id` facet
     *
     * @return list<array{name: string, count: int}>
     */
    public function categories(array $values): array
    {
        $this->load();
        $counts = [];
        foreach ($values as $value) {
            $name = $this->names[(int) $value->value] ?? null;
            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + $value->count;
            }
        }
        arsort($counts);

        return array_map(static fn(string $name, int $count): array => ['name' => $name, 'count' => $count], array_keys($counts), array_values($counts));
    }

    /** Applies the chosen category (a name) and stock to a search; an unknown name is ignored. */
    public function narrow(SearchBuilder $search, ?string $category, ?bool $inStock): SearchBuilder
    {
        $this->load();
        if ($category !== null && $category !== '' && isset($this->ids[$category])) {
            $search = $search->whereIn('category_id', $this->ids[$category]);
        }

        return $inStock !== null ? $search->where('in_stock', $inStock) : $search;
    }

    private function load(): void
    {
        if ($this->ids !== null) {
            return;
        }
        $this->ids = [];
        $this->names = [];
        /** @var list<array{id: int, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, name FROM bench_category ORDER BY id');
        foreach ($rows as $row) {
            $this->names[(int) $row['id']] = $row['name'];
            $this->ids[$row['name']][] = (int) $row['id'];
        }
    }
}
