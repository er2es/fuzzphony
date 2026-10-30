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
            '`popular` sets popularity boost 0.03, recency 0.3 and half-life 60 days. `sample` takes the profile and the thresholds of the sample configuration on the Config wizard page.',
        ],
        'text' => [
            'How much the full-text match counts: its rank (0 to 1, a match in the name counts fully, one in the description a tenth) times this weight.',
            '`projector`: at 2 the "Portable HD Projector" scores a text part of 1.05, at 1 of 0.52. With a popularity boost of 0.1, the description-only "HDMI Cable 3 m" is in the top eight at 0.2 and out of it at 2.',
        ],
        'fuzzy' => [
            'How much a word that matches approximately counts: its similarity to the query word (0 to 1) times this weight. Used only when typo tolerance runs.',
            '`hedphones` finds "Wireless headphones 6300" with a similarity of 0.62: 0.31 at the default 0.5. At 0 no typo match counts and the search finds nothing.',
        ],
        'exactBonus' => [
            'Added when the whole title equals the query, ignoring case and accents.',
            '`projector` gives the product named "Projector" the bonus and puts it first; at 0 it drops to fifth. "Projector Screen 100 inch" is longer than the query and never gets it.',
        ],
        'prefixBonus' => [
            'Added when the title starts with what you typed but is longer than it.',
            '`projector` gives it to "Projector Screen 100 inch", not to "Portable HD Projector": that word is not at the start. At 1 the four titles that start with it rise above the plain "Projector".',
        ],
        'boost' => [
            'Adds this weight times the product\'s popularity, so popular products rise. It reorders hits, it never adds one.',
            '`projector` at 0.2: the "Laser 4K Home Cinema Projector" (popularity 9.6) gains 1.92 and goes from eighth to first place.',
        ],
        'recency' => [
            'Adds up to this much for a newly published product; the bonus halves every half-life.',
            '`projector` at 1: the "Mini Pocket Projector 1016", the newest of the twelve, gains almost the whole 1 and goes from seventh to first place.',
        ],
        'halfLife' => [
            'After this many days a product\'s recency bonus has halved. It does nothing while Recency is 0.',
            '`projector` with Recency 0.5: at 1 day only the newest projectors keep a bonus; at 365 days every one of them does, and the plain "Projector" climbs from 1.02 to 1.31.',
        ],
        'minScore' => [
            'The relevance (text and fuzzy part, without bonuses and boosts) a hit needs to be listed.',
            '`projector` has 12 hits; the three that only mention a projector in their description have a relevance of 0.09, so at 0.1 the list ends at 9. `hedphones` has 0.31: at 0.32 the search finds nothing.',
        ],
        'similarityByLength' => [
            'On: every word needs the similarity its own length requires, so longer words may have more typos (one from 4 letters, two from 8). Off: the Fuzzy similarity slider sets one value for all words.',
            'On, the 9 letters of `hedphones` leave room for two typos, a 4-letter `mose` for one.',
        ],
        'similarity' => [
            'The trigram similarity each word needs to count as a typo of a product word. Lower tolerates more; used only when Similarity by word length is off.',
            '`hedphones` is 0.62 similar to "headphones": at 0.3 it still finds the headphones, at 0.7 nothing.',
        ],
        'fuzzyMode' => [
            '`fallback`: typo tolerance runs only when exact matching finds fewer hits than "Fallback below". `always`: every search. `never`: never.',
            '`hedphones` finds the headphones in `fallback` and `always`, and nothing in `never`.',
        ],
        'fallbackBelow' => [
            'In fallback mode: fewer exact hits than this switches typo tolerance on.',
            '`projector` has 12 exact hits: at 12 the list stays at 12, at 13 typo tolerance also lists words like "Professional", and the total reads 2,012+.',
        ],
        'candidateLimit' => [
            'How many candidates each branch ranks (at most 10 000). A word with more matches is cut to the best of them and the total reads as a lower bound. Higher is more exact and slower.',
            '`mouse` has more matches than the default 2 000, so the total reads "2,000+"; at 100 it reads "100+", at 10 000 "10,000+".',
        ],
        'inStockOnly' => [
            'Keeps only the products that are in stock.',
            '`projector` then loses the "Projector Lamp 240 W", the one projector that is out of stock: 11 hits.',
        ],
        'maxPrice' => [
            'Keeps only the products that cost this much or less, in Ft. 0 means any price.',
            '`projector` with 20 000 keeps 6 of the 12: the ceiling mount, the lamp, the carry case and the three cheap accessories.',
        ],
        'exactCounts' => [
            'Counts every match for the total and the facets, not only the candidates. Exact, but slower on a common word.',
            '`mouse` then reads its true total instead of "2,000+".',
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
