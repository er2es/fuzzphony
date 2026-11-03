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

    /** @internal The most hits (offset + limit) a federated search reads from every index. */
    public const int MAX_READ = 1000;

    /**
     * @internal
     *
     * @param array<string, array{weight: float, configure: (\Closure(SearchBuilder): SearchBuilder)|null}> $indexes
     */
    public function __construct(
        private Engine $engine,
        private IndexRegistry $registry,
        private array $indexes = [],
        private string $text = '',
        private int $limit = 20,
        private int $offset = 0,
        private ?SearchGate $gate = null,
        private bool $unlogged = false,
        private ?Actor $actor = null,
        private bool $system = false,
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

        return new self($this->engine, $this->registry, [...$this->indexes, $definition->name => ['weight' => $weight, 'configure' => $configure]], $this->text, $this->limit, $this->offset, $this->gate, $this->unlogged, $this->actor, $this->system);
    }

    public function query(string $text): self
    {
        return new self($this->engine, $this->registry, $this->indexes, $text, $this->limit, $this->offset, $this->gate, $this->unlogged, $this->actor, $this->system);
    }

    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $offset < 0 || $limit + $offset > self::MAX_READ) {
            throw new InvalidQuery(sprintf('A federated search reads offset + limit hits from every index: limit must be at least 1 and offset + limit at most %d, got %d + %d.', self::MAX_READ, $offset, $limit));
        }

        return new self($this->engine, $this->registry, $this->indexes, $this->text, $limit, $offset, $this->gate, $this->unlogged, $this->actor, $this->system);
    }

    /** Keeps the whole search out of the search log: every per-index search is unlogged (a refusal is still recorded). */
    public function unlogged(): self
    {
        return new self($this->engine, $this->registry, $this->indexes, $this->text, $this->limit, $this->offset, $this->gate, true, $this->actor, $this->system);
    }

    /**
     * Searches every index as this actor (each index's `configure` closure may still name another). Without it (and without
     * asSystem()) the application's current user is used, as for a single search.
     */
    public function asActor(Actor $actor): self
    {
        return new self($this->engine, $this->registry, $this->indexes, $this->text, $this->limit, $this->offset, $this->gate, $this->unlogged, $actor, false);
    }

    /** Searches every index as the system, see SearchBuilder::asSystem(). The last of asActor() / asSystem() wins. */
    public function asSystem(): self
    {
        return new self($this->engine, $this->registry, $this->indexes, $this->text, $this->limit, $this->offset, $this->gate, $this->unlogged, null, true);
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
        $charged = false;
        // who asks is decided once: the named actor, else the application's current user (the same one for every index)
        $actor = $this->system ? null : ($this->actor ?? $this->gate?->ambientActor());
        foreach ($this->indexes as $name => $options) {
            $builder = (new SearchBuilder($this->engine, $this->registry->get($name), gate: $this->gate))->query($this->text);
            if ($this->unlogged) {
                $builder = $builder->unlogged();
            }
            if ($this->system) {
                $builder = $builder->asSystem();
            } elseif ($actor !== null) {
                $builder = $builder->asActor($actor);
            }
            if ($options['configure'] !== null) {
                $builder = ($options['configure'])($builder);
            }
            // the rate limit is charged once per federated search: with the first index that is searched as an actor
            $result = $charged ? $builder->limit($read)->getUncharged() : $builder->limit($read)->get();
            $charged = $charged || $builder->toQuery()->actor !== null;
            $results[$name] = $result;
            foreach ($result->warnings as $warning) {
                $warnings[] = sprintf('[%s] %s', $name, $warning);
            }
            foreach ($result->hits as $i => $hit) {
                $candidates[] = ['score' => $options['weight'] / (self::RANK_CONSTANT + $i + 1), 'order' => $order, 'rank' => $i, 'hit' => $hit];
            }
            ++$order;
        }
        usort($candidates, static fn(array $a, array $b): int => [$b['score'], $a['order'], $a['rank']] <=> [$a['score'], $b['order'], $b['rank']]);
        $page = array_map(
            static fn(array $c): Hit => $c['hit']->withMergedScore($c['score']),
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
