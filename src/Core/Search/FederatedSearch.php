<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Search;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Registry\IndexRegistry;

/**
 * One query over several indexes, with one merged list:
 *
 *   $fuzzphony->federated()
 *       ->index('products', weight: 2.0, configure: fn(SearchBuilder $b) => $b->where('in_stock', true)->highlight('name'))
 *       ->index('articles')
 *       ->query('wireless mouse')
 *       ->limit(20)
 *       ->get();
 *
 * The scores of different indexes cannot be compared (each index ranks with its own statistics), so the
 * lists are merged by reciprocal rank fusion: a hit's merged score is `weight / (60 + its rank in its
 * own index)`, so the best hit of every index comes first, then the second best, and a weight above 1.0
 * moves an index up. Each index is searched on its own (its filters, tenant, thresholds and ranking are
 * the `configure` closure's), for the first `offset + limit` hits, so a deep page costs more than a
 * shallow one: at most 1000 hits per index are read.
 *
 * Immutable: every call returns a new instance.
 */
final readonly class FederatedSearch
{
    /** The rank constant of the fusion: the higher, the less the very first ranks outweigh the rest. */
    public const int RANK_CONSTANT = 60;

    private const int MAX_READ = 1000;

    /** @param array<string, array{weight: float, configure: (\Closure(SearchBuilder): SearchBuilder)|null}> $indexes */
    public function __construct(
        private Engine $engine,
        private IndexRegistry $registry,
        private array $indexes = [],
        private string $text = '',
        private int $limit = 20,
        private int $offset = 0,
    ) {}

    /**
     * Adds an index to the search.
     *
     * @param float                                         $weight    above 1.0 ranks the index's hits higher, below lower; must be positive
     * @param (\Closure(SearchBuilder): SearchBuilder)|null $configure what is specific to this index: filters, tenant, highlight, thresholds, profile
     */
    public function index(string $index, float $weight = 1.0, ?\Closure $configure = null): self
    {
        $definition = $this->registry->get($index); // fail fast on an unknown index
        if (isset($this->indexes[$definition->name])) {
            throw new InvalidQuery(sprintf('Index "%s" is in this federated search twice.', $definition->name));
        }
        if ($weight <= 0.0) {
            throw new InvalidQuery(sprintf('The weight of index "%s" must be positive, got %s.', $definition->name, $weight));
        }

        return new self($this->engine, $this->registry, [...$this->indexes, $definition->name => ['weight' => $weight, 'configure' => $configure]], $this->text, $this->limit, $this->offset);
    }

    public function query(string $text): self
    {
        return new self($this->engine, $this->registry, $this->indexes, $text, $this->limit, $this->offset);
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $offset < 0 || $limit + $offset > self::MAX_READ) {
            throw new InvalidQuery(sprintf('A federated search reads offset + limit hits from every index: limit must be at least 1 and offset + limit at most %d, got %d + %d.', self::MAX_READ, $offset, $limit));
        }

        return new self($this->engine, $this->registry, $this->indexes, $this->text, $limit, $offset);
    }

    public function page(int $page, int $perPage = 20): self
    {
        return $this->limit($perPage, max(0, $page - 1) * $perPage);
    }

    public function get(): FederatedResult
    {
        if ($this->indexes === []) {
            throw new InvalidQuery('A federated search needs at least one index: call index().');
        }
        $started = hrtime(true);
        $read = $this->offset + $this->limit;
        $results = [];
        $candidates = [];
        $warnings = [];
        $order = 0;
        foreach ($this->indexes as $name => $options) {
            $builder = (new SearchBuilder($this->engine, $this->registry->get($name)))->query($this->text);
            if ($options['configure'] !== null) {
                $builder = ($options['configure'])($builder);
            }
            $result = $builder->limit($read)->get();
            $results[$name] = $result;
            foreach ($result->warnings as $warning) {
                $warnings[] = sprintf('[%s] %s', $name, $warning);
            }
            foreach ($result->hits as $i => $hit) {
                $candidates[] = ['score' => $options['weight'] / (self::RANK_CONSTANT + $i + 1), 'order' => $order, 'rank' => $i, 'index' => $name, 'hit' => $hit];
            }
            ++$order;
        }
        usort($candidates, static fn(array $a, array $b): int => [$b['score'], $a['order'], $a['rank']] <=> [$a['score'], $b['order'], $b['rank']]);
        $page = array_map(
            static fn(array $c): FederatedHit => new FederatedHit($c['index'], $c['hit'], $c['score']),
            array_slice($candidates, $this->offset, $this->limit),
        );

        return new FederatedResult(
            hits: $page,
            results: $results,
            total: array_sum(array_map(static fn(SearchResult $r): int => $r->total, $results)),
            totalIsLowerBound: array_any($results, static fn(SearchResult $r): bool => $r->totalIsLowerBound),
            tookMs: round((hrtime(true) - $started) / 1e6, 3),
            warnings: array_values(array_unique($warnings)),
            limit: $this->limit,
            offset: $this->offset,
        );
    }
}
