<?php

declare(strict_types=1);

namespace App\Service;

use Twig\Markup;

/**
 * What each control of the Tune page does, in one place (the info tooltips next to the labels read it): the meaning as
 * docs/ranking.md states it, and one example from the demo's own catalogue. Backticks mark code; everything else is escaped.
 * Keep a text in step with docs/ranking.md and with how App\Twig\Components\Playground applies the value.
 */
final class SettingHelp
{
    /** key (the name of the Live prop the control writes) => [what, example] */
    private const array HELP = [
        'startFrom' => [
            'Fills the ranking sliders with the values of a ranking profile of the catalogue index in config/packages/fuzzphony.yaml.',
            '`popular` sets popularity boost 0.03, recency 0.3 and half-life 60 days.',
        ],
        'text' => [
            'How much the full-text match counts: its rank (0 to 1, a match in the name counts fully, one in the description a tenth) times this weight.',
            'At 2 a product named "Wireless mouse 1000" scores twice the text part it scores at 1.',
        ],
        'fuzzy' => [
            'How much a word that matches approximately counts: its similarity to the query word (0 to 1) times this weight. Used only when typo tolerance runs.',
            '`hedphones` finds "Wireless headphones 1020" through it: the similarity of that match times this weight.',
        ],
        'exactBonus' => [
            'Added when the whole title equals the query, ignoring case and accents.',
            '`tv wall bracket 1003` gives "TV Wall Bracket 1003" the bonus; `tv` gives it to no product, because every title is longer.',
        ],
        'prefixBonus' => [
            'Added when the title starts with what you typed but is longer than it.',
            '`tv wall` gives "TV Wall Bracket 1003" the bonus because the title starts with it; `bracket` does not, that word is not at the start.',
        ],
        'boost' => [
            'Adds this weight times the product\'s popularity, so popular products rise. It reorders hits, it never adds one.',
            'At 0.05 the "Smart Television 55 inch 1001" (popularity 8.5) gains 0.43, the "TV Wall Bracket 1003" (6.2) gains 0.31.',
        ],
        'recency' => [
            'Adds up to this much for a newly published product; the bonus halves every half-life.',
            'At 0.3 a product published today gains 0.3; with a half-life of 30 days, one from last month gains 0.15.',
        ],
        'halfLife' => [
            'After this many days a product\'s recency bonus has halved. It does nothing while Recency is 0.',
            '30 days: a product from last month counts half as much as one from today.',
        ],
        'minScore' => [
            'The relevance (text and fuzzy part, without bonuses and boosts) a hit needs to be listed.',
            '`hedphones` finds the headphones with a relevance of about 0.31: at 0.5 the search finds nothing.',
        ],
        'similarityByLength' => [
            'On: every word needs the similarity its own length requires, so longer words may have more typos (one from 4 letters, two from 8). Off: the Fuzzy similarity slider sets one value for all words.',
            'On, the 9 letters of `hedphones` leave room for two typos, a 4-letter `mose` for one.',
        ],
        'similarity' => [
            'The trigram similarity each word needs to count as a typo of a product word. Lower tolerates more; used only when Similarity by word length is off.',
            'At 1 a word must match exactly; at 0.3 `hedphones` still reaches "Wireless headphones 1020".',
        ],
        'fuzzyMode' => [
            '`fallback`: typo tolerance runs only when exact matching finds fewer hits than "Fallback below". `always`: every search. `never`: never.',
            '`hedphones` finds the headphones in `fallback` and `always`, and nothing in `never`.',
        ],
        'fallbackBelow' => [
            'In fallback mode: fewer exact hits than this switches typo tolerance on.',
            'At 5, a search with 3 exact hits also looks for typos; at 1, only a search with no hit does.',
        ],
        'candidateLimit' => [
            'How many candidates each branch ranks (at most 10 000). A word with more matches is cut to the best of them and the total reads as a lower bound. Higher is more exact and slower.',
            'On the 500 000-product catalogue `mouse` has more matches than the default 2 000, so the total reads "2 000+".',
        ],
        'inStockOnly' => [
            'Keeps only the products that are in stock.',
            '`mouse` then lists only the mice that are in stock.',
        ],
        'maxPrice' => [
            'Keeps only the products that cost this much or less, in Ft. 0 means any price.',
            '`headphones` with 20 000 hides every pair above 20 000 Ft.',
        ],
        'exactCounts' => [
            'Counts every match for the total and the facets, not only the candidates. Exact, but slower on a common word.',
            '`mouse` then reads its true total instead of "2 000+".',
        ],
    ];

    /** @return array{what: Markup, example: Markup}|null null for a key without a text */
    public function get(string $key): ?array
    {
        if (!isset(self::HELP[$key])) {
            return null;
        }
        [$what, $example] = self::HELP[$key];

        return ['what' => self::html($what), 'example' => self::html($example)];
    }

    private static function html(string $text): Markup
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Markup((string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped), 'UTF-8');
    }
}
