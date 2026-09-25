<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The /languages page: the multilingual catalogue (sql/lang_product.sql), one index per language, and read-only
 * SQL that shows what PostgreSQL makes of the words of a query with the index's text search configuration.
 *
 * Every value that reaches SQL is a bound parameter; the configuration names come from the LANGUAGES list only.
 */
final readonly class Languages
{
    /**
     * code => settings. `config` is what `fuzzphony:schema --apply` creates for the index (built-in configuration,
     * stop words dropped, then unaccent before the stemmer), `builtin` the configuration it copies (used to show what
     * folding changed).
     * Preset captions mark words with backticks; the template renders those as <code>.
     */
    public const array LANGUAGES = [
        'en' => [
            'name' => 'English', 'native' => 'English', 'index' => 'lang_en', 'config' => 'fuzzphony_english', 'builtin' => 'english',
            'presets' => [
                ['kind' => 'plural', 'q' => 'drills', 'caption' => '`drills` finds `drill`: the stemmer cuts the plural -s.'],
                ['kind' => 'word form', 'q' => 'running', 'caption' => '`running` finds `run` and `runs`: -ing is stemmed away too.'],
                ['kind' => 'accents', 'q' => 'creme brulee', 'caption' => '`creme brulee` finds `Crème Brûlée`: accents are removed before indexing.'],
                ['kind' => 'stop words', 'q' => 'a lamp for the desk', 'caption' => '`a`, `for` and `the` are ignored, so only `lamp` and `desk` have to match.'],
                ['kind' => 'typo', 'q' => 'hedphones', 'caption' => '`hedphones` still finds `Headphones`: typo tolerance compares letter triples.'],
                ['kind' => 'irregular form: not matched', 'q' => 'mice', 'caption' => '`mice` does not find `mouse`: stemmers only cut regular endings.'],
            ],
        ],
        'de' => [
            'name' => 'German', 'native' => 'Deutsch', 'index' => 'lang_de', 'config' => 'fuzzphony_german', 'builtin' => 'german',
            'presets' => [
                ['kind' => 'plural', 'q' => 'Häuser', 'caption' => '`Häuser` finds `Haus`: the umlaut is folded, then the plural ending stemmed.'],
                ['kind' => 'plural', 'q' => 'Mäuse', 'caption' => '`Mäuse` finds `Maus`, although in English `mice` cannot find `mouse`.'],
                ['kind' => 'plural', 'q' => 'Bücher', 'caption' => '`Bücher` finds both books called `Buch`.'],
                ['kind' => 'stop words', 'q' => 'die Tasche aus Filz', 'caption' => '`die` and `aus` are German stop words: only `Tasche` and `Filz` have to match.'],
                ['kind' => 'accents', 'q' => 'Kuhlbox', 'caption' => '`Kuhlbox`, typed without the umlaut, finds `Kühlbox`.'],
                ['kind' => 'typo', 'q' => 'Kafeemaschine', 'caption' => '`Kafeemaschine` (one f missing) finds `Kaffeemaschine`.'],
            ],
        ],
        'fr' => [
            'name' => 'French', 'native' => 'Français', 'index' => 'lang_fr', 'config' => 'fuzzphony_french', 'builtin' => 'french',
            'presets' => [
                ['kind' => 'plural', 'q' => 'chevaux', 'caption' => '`chevaux` finds `cheval`: the French stemmer knows the -aux plural.'],
                ['kind' => 'plural', 'q' => 'crèmes', 'caption' => '`crèmes` finds every `crème`, singular or plural.'],
                ['kind' => 'accents', 'q' => 'ecouteurs', 'caption' => '`ecouteurs` without the accent finds `Écouteurs`.'],
                ['kind' => 'stop words', 'q' => 'sac pour le vélo', 'caption' => '`pour` and `le` are ignored: only `sac` and `vélo` have to match.'],
                ['kind' => 'accented stop word', 'q' => 'machine à café', 'caption' => '`à` is a stop word too, accent and all: only `machine` and `café` have to match.'],
                ['kind' => 'typo', 'q' => 'aspirater', 'caption' => '`aspirater` still finds `Aspirateur`.'],
            ],
        ],
        'es' => [
            'name' => 'Spanish', 'native' => 'Español', 'index' => 'lang_es', 'config' => 'fuzzphony_spanish', 'builtin' => 'spanish',
            'presets' => [
                ['kind' => 'plural', 'q' => 'canciones', 'caption' => '`canciones` finds `canción`: accent folded, plural stemmed.'],
                ['kind' => 'accents', 'q' => 'lampara', 'caption' => '`lampara` without the accent finds `Lámpara` and `Lámparas`.'],
                ['kind' => 'accents', 'q' => 'raton', 'caption' => '`raton` finds `Ratón`.'],
                ['kind' => 'stop words', 'q' => 'funda para el móvil', 'caption' => '`para` and `el` are ignored: only `funda` and `móvil` have to match.'],
                ['kind' => 'typo', 'q' => 'bicicelta', 'caption' => '`bicicelta` (two letters swapped) finds `Bicicleta`.'],
            ],
        ],
        'hu' => [
            'name' => 'Hungarian', 'native' => 'Magyar', 'index' => 'lang_hu', 'config' => 'fuzzphony_hungarian', 'builtin' => 'hungarian',
            'presets' => [
                ['kind' => 'plural', 'q' => 'házak', 'caption' => '`házak` finds `ház`: the Hungarian stemmer reduces the plural.'],
                ['kind' => 'plural', 'q' => 'könyvek', 'caption' => '`könyvek` finds `Könyv`.'],
                ['kind' => 'accent folding', 'q' => 'egerek', 'caption' => '`egerek` finds `egér` only because accents are removed before stemming.'],
                ['kind' => 'accents', 'q' => 'kavefozo', 'caption' => '`kavefozo`, typed without accents, finds `Kávéfőző`.'],
                ['kind' => 'stop words', 'q' => 'lámpa az asztalra', 'caption' => '`az` is ignored and `asztalra` (onto the desk) is stemmed to `asztal`.'],
                ['kind' => 'typo', 'q' => 'kenyérpritó', 'caption' => '`kenyérpritó` still finds `Kenyérpirító`.'],
            ],
        ],
    ];

    /** Token types of ts_debug that are words (compounds are shown by their parts). */
    private const array WORD_TOKENS = ['asciiword', 'word', 'numword', 'hword_part', 'hword_asciipart', 'hword_numpart'];

    public function __construct(private Connection $connection) {}

    /**
     * What happens to every word of $text: the lexeme the index stores for it, what accent folding changed and
     * how many products of this language contain the word (exactly, without typo tolerance).
     *
     * @param array{config: string, builtin: string} $language
     *
     * @return list<array{token: string, folded: string, lexeme: ?string, plainLexeme: ?string, docs: int}>
     */
    public function analyse(string $code, array $language, string $text): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.token,
                    unaccent(d.token) AS folded,
                    (tsvector_to_array(to_tsvector(CAST(:config AS regconfig), d.token)))[1] AS lexeme,
                    (tsvector_to_array(to_tsvector(CAST(:builtin AS regconfig), d.token)))[1] AS plain_lexeme,
                    CASE WHEN numnode(plainto_tsquery(CAST(:config AS regconfig), d.token)) = 0 THEN 0 ELSE (
                        SELECT count(*) FROM lang_product p
                        WHERE p.lang = :lang
                          AND to_tsvector(CAST(:config AS regconfig), p.name || \' \' || p.description || \' \' || p.category)
                              @@ plainto_tsquery(CAST(:config AS regconfig), d.token)
                    ) END AS docs
             FROM ts_debug(CAST(:config AS regconfig), :text) d
             WHERE d.alias IN (:aliases)
             LIMIT 16',
            ['config' => $language['config'], 'builtin' => $language['builtin'], 'lang' => $code, 'text' => $text, 'aliases' => self::WORD_TOKENS],
            ['aliases' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): array => [
            'token' => (string) $row['token'],
            'folded' => (string) $row['folded'],
            'lexeme' => $row['lexeme'] === null ? null : (string) $row['lexeme'],
            'plainLexeme' => $row['plain_lexeme'] === null ? null : (string) $row['plain_lexeme'],
            'docs' => (int) $row['docs'],
        ], $rows);
    }

    /**
     * The naive search for comparison: every word, in order, as a substring of the name, description or category.
     *
     * @return list<int>
     */
    public function ilikeIds(string $code, string $text): array
    {
        $pattern = '%' . implode('%', preg_split('/\s+/', trim(addcslashes($text, '%_\\'))) ?: []) . '%';

        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM lang_product
             WHERE lang = :lang AND (name ILIKE :q OR description ILIKE :q OR category ILIKE :q)
             ORDER BY id',
            ['lang' => $code, 'q' => $pattern],
        ));
    }

    /** @return array<int, array{id: int, name: string, description: string, category: string}> the whole catalogue of one language */
    public function products(string $code): array
    {
        /** @var list<array{id: int, name: string, description: string, category: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, description, category FROM lang_product WHERE lang = :lang ORDER BY category, name',
            ['lang' => $code],
        );

        return array_column($rows, null, 'id');
    }
}
