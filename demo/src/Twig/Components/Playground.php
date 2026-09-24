<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Catalog;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Every ranking weight and threshold as a slider; results, score breakdowns and the SQL update live.
 *
 * Both panels (results, SQL / EXPLAIN) are rendered on every update and the template switches between them
 * client-side, so the Results / SQL radio never waits for the server.
 */
#[AsLiveComponent]
final class Playground
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)] public string $query = 'wireles mouse';
    #[LiveProp(writable: true)] public bool $analyze = false;
    #[LiveProp(writable: true)] public bool $inStockOnly = false;
    #[LiveProp(writable: true)] public int $maxPrice = 0;

    // ranking profile
    #[LiveProp(writable: true)] public float $text = 1.0;
    #[LiveProp(writable: true)] public float $fuzzy = 0.5;
    #[LiveProp(writable: true)] public float $exactBonus = 0.5;
    #[LiveProp(writable: true)] public float $prefixBonus = 0.2;
    #[LiveProp(writable: true)] public float $boost = 0.0;
    #[LiveProp(writable: true)] public float $recency = 0.0;
    #[LiveProp(writable: true)] public float $halfLife = 30.0;

    // thresholds
    #[LiveProp(writable: true)] public float $minScore = 0.01;
    #[LiveProp(writable: true)] public float $similarity = 0.3;
    #[LiveProp(writable: true)] public string $fuzzyMode = 'fallback';
    #[LiveProp(writable: true)] public int $fallbackBelow = 5;
    #[LiveProp(writable: true)] public int $candidateLimit = 2000;

    private ?SearchResult $result = null;
    private ?Explanation $explanation = null;
    private ?string $error = null;

    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly Catalog $catalog,
    ) {}

    public function getResult(): ?SearchResult
    {
        $this->run();

        return $this->result;
    }

    public function getExplanation(): ?Explanation
    {
        $this->run();

        return $this->explanation;
    }

    public function getError(): ?string
    {
        $this->run();

        return $this->error;
    }

    /** @return array<int|string, array<string, mixed>> */
    public function getRows(): array
    {
        return $this->catalog->rows($this->getResult()?->ids() ?? []);
    }

    private function run(): void
    {
        if ($this->result !== null || $this->error !== null) {
            return;
        }
        try {
            // explain() runs the search itself, so this is one query for both panels (plus EXPLAIN [ANALYZE]).
            $this->explanation = $this->search()->explain($this->analyze);
            $this->result = $this->explanation->result;
        } catch (FuzzphonyException $e) {
            // invalid slider combinations are explained, not thrown
            $this->error = $e->getMessage();
        }
    }

    private function search(): SearchBuilder
    {
        $search = $this->fuzzphony->in('catalog')
            ->query($this->query)
            ->highlight('name')
            ->limit(20)
            ->ranking([
                'text' => $this->text,
                'fuzzy' => $this->fuzzy,
                'exact_bonus' => $this->exactBonus,
                'prefix_bonus' => $this->prefixBonus,
                'boost' => $this->boost,
                'recency' => $this->recency,
                'recency_half_life_days' => max(1.0, $this->halfLife),
            ])
            ->thresholds([
                'min_score' => $this->minScore,
                'fuzzy_similarity' => max(0.05, $this->similarity),
                'fuzzy_mode' => $this->fuzzyMode,
                'fallback_below' => max(1, $this->fallbackBelow),
                'candidate_limit' => max(10, $this->candidateLimit),
            ]);
        if ($this->inStockOnly) {
            $search = $search->where('in_stock', true);
        }
        if ($this->maxPrice > 0) {
            $search = $search->where('price', '<=', $this->maxPrice);
        }

        return $search;
    }
}
