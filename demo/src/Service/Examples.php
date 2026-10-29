<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Every example text of the demo, in one place: the words that show a thing (a typo, an accent, a beginning, a synonym) are named once,
 * and each page picks the list it needs. A word in two lists is the same word.
 */
final class Examples
{
    public const string TYPO = 'hedphones';
    public const string ACCENT = 'creme';
    public const string BEGINNING = 'cr';
    public const string SYNONYM = 'tv';
    public const string WORD_FORM = 'drills';
    public const string RESCUE = 'mpuse';

    /** The tokens under the home page's field: label => query. */
    public const array HOME = [
        'Typos' => self::TYPO,
        'Beginnings' => self::BEGINNING,
        'Meaning' => self::SYNONYM,
        'Accents' => self::ACCENT,
        'Word forms' => self::WORD_FORM,
    ];

    /** The Observability page's tokens and the first seven queries of the Benchmark: label => query. */
    public const array CATALOGUE = [
        'accent' => self::ACCENT,
        'typo' => self::TYPO,
        'stemming' => self::WORD_FORM,
        'phrase + exclude' => '"noise cancelling" -headphones',
        'field' => 'category:kitchen kettle',
        'exact field' => 'brand:sony headphones',
        'prefix' => 'ergono*',
    ];

    /** The Benchmark's nine queries: the catalogue examples and two plain ones. */
    public const array BENCHMARK = self::CATALOGUE + ['plain word' => 'wireless', 'two words' => 'wireless mouse'];

    /** The Relevance page: group => label => query. */
    public const array RELEVANCE = [
        'Typo tolerance by word length' => [
            'a correct short word' => 'mouse',
            'a typo in a four-letter word' => 'mose',
            'a typo in a long word' => self::TYPO,
            'a typo and a correct word' => 'wireles mouse',
            'the price: a letter replaced in the middle' => self::RESCUE,
        ],
        'Synonyms' => [
            'an abbreviation' => self::SYNONYM,
            'an irregular plural' => 'mice',
            'another word for a monitor' => 'display',
            'a headset' => 'headset',
            'one way only: a drill finds screwdrivers' => 'drill',
            '... but not the other way round' => 'screwdriver',
        ],
        'Did you mean' => [
            'a misspelled word' => self::TYPO,
            'inside a query' => 'ergonmic -silent',
            'a phrase' => '"noise cancelling" headphnoes',
            'a word of the vocabulary: nothing to suggest' => 'headphones',
        ],
    ];

    /** The Security page, on the intranet notes: label => query. */
    public const array INTRANET = [
        'only in a hidden field' => 'salary',
        'in both companies' => 'budget',
        'also only in a hidden field' => 'lawsuit',
        'in a title and a hidden field' => 'bonus',
        'ordinary' => 'menu',
    ];
}
