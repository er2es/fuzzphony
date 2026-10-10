<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Catalog;
use App\Service\Facets;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Every ranking weight and threshold as a slider; results, score breakdowns and the SQL update live.
 *
 * Both panels (results, SQL / EXPLAIN) are rendered on every update and the template switches between them
 * client-side, so the Results / SQL radio never waits for the server.
 *
 * Every writable prop comes from the browser, so it is clamped to the range its control offers (see
 * normalize()) before it reaches the search; EXPLAIN ANALYZE runs the query a second time and is only
 * available when DEMO_ALLOW_ANALYZE=1.
 */
#[AsLiveComponent]
final class Playground
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)] public string $query = 'wireles mouse';
    #[LiveProp(writable: true)] public bool $analyze = false;
    #[LiveProp(writable: true)] public bool $inStockOnly = false;
    #[LiveProp(writable: true)] public int $maxPrice = 0;
    /** The category chosen on the facet (a name, '' = any). */
    #[LiveProp(writable: true)] public string $category = '';
    #[LiveProp(writable: true)] public bool $exactCounts = false;

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
    #[LiveProp(writable: true)] public bool $similarityByLength = true;
    #[LiveProp(writable: true)] public float $similarity = 0.3;
    #[LiveProp(writable: true)] public string $fuzzyMode = 'fallback';
    #[LiveProp(writable: true)] public int $fallbackBelow = 5;
    #[LiveProp(writable: true)] public int $candidateLimit = 2000;

    private ?SearchResult $result = null;
    private ?Explanation $explanation = null;
    private ?string $error = null;

    /** Slider ranges of the template (prop => [min, max]); a value from outside is clamped into it. */
    public const array RANGES = [
        'text' => [0.0, 2.0],
        'fuzzy' => [0.0, 2.0],
        'exactBonus' => [0.0, 2.0],
        'prefixBonus' => [0.0, 1.0],
        'boost' => [0.0, 0.2],
        'recency' => [0.0, 1.0],
        'halfLife' => [1.0, 365.0],
        'minScore' => [0.0, 1.0],
        'similarity' => [0.05, 1.0],
        'fallbackBelow' => [1, 50],
        'candidateLimit' => [100, 10_000],
        'maxPrice' => [0, 200_000],
    ];

    public function __construct(
        private readonly Fuzzphony $fuzzphony,
        private readonly Catalog $catalog,
        private readonly Facets $facets,
        #[Autowire('%env(bool:DEMO_ALLOW_ANALYZE)%')] private readonly bool $analyzeAllowed = false,
        #[Autowire('%env(bool:DEMO_ALLOW_EXACT)%')] private readonly bool $exactAllowed = false,
    ) {}

    public function isAnalyzeAllowed(): bool
    {
        return $this->analyzeAllowed;
    }

    public function isExactAllowed(): bool
    {
        return $this->exactAllowed;
    }

    /** The category chosen again lifts the choice. */
    #[LiveAction]
    public function pickCategory(#[LiveArg] string $name): void
    {
        $this->category = $this->category === $name ? '' : mb_substr($name, 0, 40);
    }

    #[LiveAction]
    public function toggleInStock(): void
    {
        $this->inStockOnly = !$this->inStockOnly;
    }

    /** @return list<array{name: string, count: int}> the category facet of the result, by name */
    public function getCategories(): array
    {
        return $this->facets->categories($this->getResult()?->facets['category_id'] ?? []);
    }

    /** Runs after every hydration from the browser, so the re-rendered controls show the values actually used. */
    #[PostHydrate]
    public function normalize(): void
    {
        $this->text = self::clampFloat($this->text, ...self::RANGES['text']);
        $this->fuzzy = self::clampFloat($this->fuzzy, ...self::RANGES['fuzzy']);
        $this->exactBonus = self::clampFloat($this->exactBonus, ...self::RANGES['exactBonus']);
        $this->prefixBonus = self::clampFloat($this->prefixBonus, ...self::RANGES['prefixBonus']);
        $this->boost = self::clampFloat($this->boost, ...self::RANGES['boost']);
        $this->recency = self::clampFloat($this->recency, ...self::RANGES['recency']);
        $this->halfLife = self::clampFloat($this->halfLife, ...self::RANGES['halfLife']);
        $this->minScore = self::clampFloat($this->minScore, ...self::RANGES['minScore']);
        $this->similarity = self::clampFloat($this->similarity, ...self::RANGES['similarity']);
        $this->fallbackBelow = self::clampInt($this->fallbackBelow, ...self::RANGES['fallbackBelow']);
        $this->candidateLimit = self::clampInt($this->candidateLimit, ...self::RANGES['candidateLimit']);
        $this->maxPrice = self::clampInt($this->maxPrice, ...self::RANGES['maxPrice']);
        $this->fuzzyMode = (FuzzyMode::tryFrom($this->fuzzyMode) ?? FuzzyMode::Fallback)->value;
        $this->analyze = $this->analyze && $this->analyzeAllowed;
        $this->exactCounts = $this->exactCounts && $this->exactAllowed;
        $this->category = mb_substr($this->category, 0, 40);
    }

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
        $this->normalize();
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
                'recency_half_life_days' => $this->halfLife,
            ])
            ->thresholds([
                'min_score' => $this->minScore,
                'fuzzy_similarity' => $this->similarityByLength ? null : $this->similarity,
                'fuzzy_mode' => $this->fuzzyMode,
                'fallback_below' => $this->fallbackBelow,
                'candidate_limit' => $this->candidateLimit,
            ]);
        if ($this->inStockOnly) {
            $search = $search->where('in_stock', true);
        }
        $search = $this->facets->narrow($search, $this->category, null)->facets('category_id', 'in_stock');
        if ($this->exactCounts) {
            $search = $search->exactCounts();
        }
        if ($this->maxPrice > 0) {
            $search = $search->where('price', '<=', $this->maxPrice);
        }

        return $search;
    }

    private static function clampFloat(float $value, float $min, float $max): float
    {
        return is_nan($value) ? $min : max($min, min($max, $value));
    }

    private static function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
