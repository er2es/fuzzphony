<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Service\Catalog;
use App\Service\Examples;
use App\Service\Facets;
use App\Service\SampleConfig;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\FuzzyMode;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Search\Explanation;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
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

    /*
     * Every control is in the address, so a reload or a shared link reproduces the page; only a value that differs from the default is
     * written (App\EventListener\LiveUrlDefaults drops the rest). The text is `q`, as on every other page.
     */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'q'))] public string $query = Examples::TUNE_START;
    #[LiveProp(writable: true, url: true)] public bool $analyze = false;
    /** The stock facet: '' = any, 'in' or 'out' (the same values as on the search page). */
    #[LiveProp(writable: true, url: true)] public string $stock = '';
    #[LiveProp(writable: true, url: true)] public int $maxPrice = 0;
    /** The category chosen on the facet (a name, '' = any). */
    #[LiveProp(writable: true, url: true)] public string $category = '';
    #[LiveProp(writable: true, url: true)] public bool $exactCounts = false;
    /** Which panel is shown: 'results' or 'sql' (the radios switch it in the browser, see history_controller.js). */
    #[LiveProp(writable: true, url: true)] public string $view = 'results';

    // ranking profile
    #[LiveProp(writable: true, url: true)] public float $text = 1.0;
    #[LiveProp(writable: true, url: true)] public float $fuzzy = 0.5;
    #[LiveProp(writable: true, url: true)] public float $exactBonus = 0.5;
    #[LiveProp(writable: true, url: true)] public float $prefixBonus = 0.2;
    #[LiveProp(writable: true, url: true)] public float $boost = 0.0;
    #[LiveProp(writable: true, url: true)] public float $recency = 0.0;
    #[LiveProp(writable: true, url: true)] public float $halfLife = 30.0;

    // thresholds
    #[LiveProp(writable: true, url: true)] public float $minScore = 0.01;
    #[LiveProp(writable: true, url: true)] public bool $similarityByLength = true;
    #[LiveProp(writable: true, url: true)] public float $similarity = 0.3;
    #[LiveProp(writable: true, url: true)] public string $fuzzyMode = 'fallback';
    #[LiveProp(writable: true, url: true)] public int $fallbackBelow = 5;
    #[LiveProp(writable: true, url: true)] public int $candidateLimit = 2000;

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
        private readonly SampleConfig $sample,
        #[Autowire('%env(bool:DEMO_ALLOW_ANALYZE)%')] private readonly bool $analyzeAllowed = false,
        #[Autowire('%env(bool:DEMO_ALLOW_EXACT)%')] private readonly bool $exactAllowed = false,
    ) {}

    /** @return array<string, string> label => query, for the chips under the search field */
    public function getExamples(): array
    {
        return Examples::TUNE;
    }

    public function isAnalyzeAllowed(): bool
    {
        return $this->analyzeAllowed;
    }

    public function isExactAllowed(): bool
    {
        return $this->exactAllowed;
    }

    /** An example chip puts its query into the field; only the queries of Examples::TUNE are taken, the sliders stay where they are. */
    #[LiveAction]
    public function tryQuery(#[LiveArg] string $text): void
    {
        if (in_array($text, Examples::TUNE, true)) {
            $this->query = $text;
            $this->clearFilters(); // an example is a fresh start: no filter left from before may hide what it names
        }
    }

    /** The stock facet chosen again lifts the choice; only `in` and `out` are taken. */
    #[LiveAction]
    public function pickStock(#[LiveArg] string $value): void
    {
        if (in_array($value, ['in', 'out'], true)) {
            $this->stock = $this->stock === $value ? '' : $value;
        }
    }

    /** The category chosen again lifts the choice. */
    #[LiveAction]
    public function pickCategory(#[LiveArg] string $name): void
    {
        $this->category = $this->category === $name ? '' : mb_substr($name, 0, 40);
    }

    /** Opened from the Config wizard's sample configuration (/tune?start=sample): the sliders start from it. */
    public function mount(string $start = ''): void
    {
        if ($start === 'sample') {
            $this->startFrom('sample');
        }
    }

    /**
     * The ranking sliders take the values of a profile of the catalogue index (`default`, or `popular` in config/packages/fuzzphony.yaml):
     * the profile is read from the index definition, so the page and the configuration cannot drift apart. `sample` is the Config wizard's
     * sample configuration (App\Service\SampleConfig): its default profile and its thresholds.
     */
    #[LiveAction]
    public function startFrom(#[LiveArg] string $name): void
    {
        if ($name === 'sample') {
            $definition = $this->sample->definition();
            $this->applyProfile($definition->profile('default'));
            $this->applyThresholds($definition->thresholds);

            return;
        }
        if (!in_array($name, ['default', 'popular'], true)) {
            return;
        }
        $definition = $this->fuzzphony->definition('catalog');
        $this->applyProfile($definition->profile($name));
        $this->applyThresholds($definition->thresholds);
    }

    private function applyThresholds(Thresholds $thresholds): void
    {
        $this->minScore = $thresholds->relevance->minScore;
        $this->similarityByLength = $thresholds->typo->similarity === null;
        $this->similarity = $thresholds->typo->similarity ?? 0.3;
        $this->fuzzyMode = $thresholds->typo->mode->value;
        $this->fallbackBelow = $thresholds->typo->fallbackBelow;
        $this->candidateLimit = $thresholds->limits->candidates;
    }

    private function applyProfile(RankingProfile $profile): void
    {
        $this->text = $profile->text;
        $this->fuzzy = $profile->fuzzy;
        $this->exactBonus = $profile->exactBonus;
        $this->prefixBonus = $profile->prefixBonus;
        $this->boost = $profile->boost;
        $this->recency = $profile->recency;
        $this->halfLife = $profile->recencyHalfLifeDays;
    }

    /** A chosen filter shown as a token is lifted by its cross; only the names below are taken. */
    #[LiveAction]
    public function clearFilter(#[LiveArg] string $name): void
    {
        match ($name) {
            'category' => $this->category = '',
            'stock' => $this->stock = '',
            'price' => $this->maxPrice = 0,
            default => null,
        };
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->category = '';
        $this->stock = '';
        $this->maxPrice = 0;
    }

    /** @return int how many filters are in force (the token row offers "Clear all" from two) */
    public function getFilterCount(): int
    {
        return (int) ($this->category !== '') + (int) ($this->stock !== '') + (int) ($this->maxPrice > 0);
    }

    /** The profile (`default`, `popular`, `sample`) whose values the controls show right now, '' when they match none: its chip is marked. */
    public function getActiveProfile(): string
    {
        $now = [$this->text, $this->fuzzy, $this->exactBonus, $this->prefixBonus, $this->boost, $this->recency, $this->halfLife, $this->minScore, $this->similarityByLength ? 1.0 : 0.0, $this->similarityByLength ? 0.0 : $this->similarity, $this->fuzzyMode, $this->fallbackBelow, $this->candidateLimit];
        $catalog = $this->fuzzphony->definition('catalog');
        foreach (['default' => $catalog, 'popular' => $catalog, 'sample' => $this->sample->definition()] as $name => $definition) {
            $p = $definition->profile($name === 'sample' ? 'default' : $name);
            $t = $definition->thresholds;
            $values = [$p->text, $p->fuzzy, $p->exactBonus, $p->prefixBonus, $p->boost, $p->recency, $p->recencyHalfLifeDays, $t->relevance->minScore, $t->typo->similarity === null ? 1.0 : 0.0, $t->typo->similarity ?? 0.0, $t->typo->mode->value, $t->typo->fallbackBelow, $t->limits->candidates];
            if (self::same($now, $values)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * @param list<float|int|string> $a
     * @param list<float|int|string> $b
     */
    private static function same(array $a, array $b): bool
    {
        foreach ($a as $i => $v) {
            if (is_string($v) || is_string($b[$i]) ? $v !== $b[$i] : abs($v - $b[$i]) > 1e-9) {
                return false;
            }
        }

        return true;
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
        $this->stock = in_array($this->stock, ['in', 'out'], true) ? $this->stock : '';
        $this->view = $this->view === 'sql' ? 'sql' : 'results';
    }

    public function getResult(): ?SearchResult
    {
        $this->run();

        return $this->result;
    }

    /** True when filters are in force and no hit of the page holds a word of the query itself (every hit is a look-alike): the words match nothing inside the filters. */
    public function isLookAlikeOnly(): bool
    {
        $result = $this->getResult();
        if ($this->getFilterCount() === 0 || $result === null || $result->hits === []) {
            return false;
        }
        foreach ($result->hits as $hit) {
            if ($hit->breakdown->textRank > 0.0) {
                return false;
            }
        }

        return true;
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
            ->asSystem()
            ->query($this->query)
            ->asYouType()
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
            ->thresholds(['relevance' => ['min_score' => $this->minScore], 'typo' => ['similarity' => $this->similarityByLength ? null : $this->similarity, 'mode' => $this->fuzzyMode, 'fallback_below' => $this->fallbackBelow], 'limits' => ['candidates' => $this->candidateLimit]]);
        if ($this->stock !== '') {
            $search = $search->where('in_stock', $this->stock === 'in');
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
