<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/**
 * Accent folding must not keep accented stop words: unaccent runs before the stem dictionary,
 * which would check its stop-word list against "fur" instead of "für".
 */
final class AccentedStopWordsTest extends TestCase
{
    private const array LANGUAGES = ['de' => 'german', 'hu' => 'hungarian', 'fr' => 'french', 'ro' => 'romanian', 'xx' => 'simple'];

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_lang_item CASCADE');
        foreach (self::LANGUAGES as $language) {
            $this->connection->execute(sprintf('DROP TABLE IF EXISTS "fuzzphony_items_%s" CASCADE', $language));
            $this->connection->execute(sprintf('DROP TEXT SEARCH CONFIGURATION IF EXISTS "fuzzphony_%s"', $language));
            $this->connection->execute(sprintf('DROP TEXT SEARCH DICTIONARY IF EXISTS "fuzzphony_%s_stop"', $language));
        }
        $this->connection->execute('CREATE TABLE fz_lang_item (id bigint PRIMARY KEY, lang text NOT NULL, title text NOT NULL)');
        $rows = [
            [1, 'de', 'Tasche für Laptop'],
            [2, 'de', 'Laptop Tasche'],
            [3, 'de', 'Häuser am See'],
            [11, 'hu', 'Egér és billentyűzet'],
            [12, 'hu', 'Vezeték nélküli egér'],
            [21, 'fr', 'Sac à dos'],
            [22, 'fr', 'Grand sac dos'],
            [31, 'ro', 'Mașină și casă'],
            [41, 'xx', 'Crème brûlée'],
        ];
        foreach ($rows as [$id, $lang, $title]) {
            $this->connection->execute('INSERT INTO fz_lang_item VALUES (:id, :lang, :title)', ['id' => $id, 'lang' => $lang, 'title' => $title]);
        }
    }

    private function fuzzphony(): Fuzzphony
    {
        $indexes = [];
        foreach (self::LANGUAGES as $code => $language) {
            $indexes[] = IndexDefinition::builder('items_' . $language)
                ->fromQuery(sprintf("SELECT id, title FROM fz_lang_item WHERE lang = '%s'", $code))
                ->watch('fz_lang_item')
                ->field('title', 'A', fuzzy: true)
                ->language($language)
                ->sync('manual')
                ->build();
        }
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry($indexes));
        $fuzzphony->schema()->apply($this->connection);
        foreach (self::LANGUAGES as $language) {
            $fuzzphony->reindex('items_' . $language);
        }

        return $fuzzphony;
    }

    private function tsvector(string $config, string $text): string
    {
        return Coerce::str($this->connection->fetchValue('SELECT to_tsvector(CAST(:config AS regconfig), :text)::text', ['config' => $config, 'text' => $text]));
    }

    private function tsquery(string $config, string $text): string
    {
        return Coerce::str($this->connection->fetchValue('SELECT plainto_tsquery(CAST(:config AS regconfig), :text)::text', ['config' => $config, 'text' => $text]));
    }

    /** @return list<string> dictionaries of the "word" token, in order */
    private function wordMapping(string $config): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
                SELECT d.dictname FROM pg_ts_config c
                JOIN pg_ts_config_map m ON m.mapcfg = c.oid
                JOIN pg_ts_dict d ON d.oid = m.mapdict
                WHERE c.cfgname = :config AND m.maptokentype = (SELECT t.tokid FROM ts_token_type(c.cfgparser) AS t WHERE t.alias = 'word')
                ORDER BY m.mapseqno
                SQL,
            ['config' => $config],
        );

        return array_map(static fn(array $row): string => Coerce::str($row['dictname']), $rows);
    }

    public function testAccentedStopWordsAreDroppedFromDocumentsAndQueries(): void
    {
        $this->fuzzphony();

        self::assertSame("'laptop':3 'tasch':1", $this->tsvector('fuzzphony_german', 'Tasche für Laptop'));
        self::assertSame("'billentyuz':3 'eger':1", $this->tsvector('fuzzphony_hungarian', 'Egér és billentyűzet'));
        self::assertSame("'dos':3 'sac':1", $this->tsvector('fuzzphony_french', 'Sac À dos'));
        self::assertSame("'tasch' & 'laptop'", $this->tsquery('fuzzphony_german', 'Tasche FÜR Laptop'));
        self::assertSame("'eger'", $this->tsquery('fuzzphony_hungarian', 'egér és'));
        self::assertSame('', $this->tsquery('fuzzphony_french', 'à'));
        self::assertSame("'haus':1 'see':3", $this->tsvector('fuzzphony_german', 'Häuser am See'), 'accented words still fold and stem');
        self::assertSame("'fussball':2 'fussball-gross':1 'gross':3", $this->tsvector('fuzzphony_german', 'Fußball-Größe'), 'hyphenated words too');
    }

    public function testSearchesBehaveAsIfTheStopWordWereAbsent(): void
    {
        $fuzzphony = $this->fuzzphony();
        $ids = static fn(string $index, string $query, string $fuzzyMode = 'never'): array => $fuzzphony->in($index)->query($query)
            ->thresholds(['fuzzy_mode' => $fuzzyMode])->get()->ids();

        self::assertEqualsCanonicalizing([1, 2], $ids('items_german', 'Tasche für Laptop'));
        self::assertEqualsCanonicalizing([11, 12], $ids('items_hungarian', 'egér és'));
        self::assertEqualsCanonicalizing([21, 22], $ids('items_french', 'sac à dos'));
        self::assertSame([], $ids('items_french', 'à'), 'a lone stop word matches nothing');
        self::assertSame([3], $ids('items_german', 'haus'));
        self::assertEqualsCanonicalizing([11, 12], $ids('items_hungarian', 'eger'), 'accent folding still works');

        // The per-word fuzzy branch ignores what the configuration reduces to nothing.
        self::assertEqualsCanonicalizing([1, 2], $ids('items_german', 'Tasche für Laptopp', 'always'));
        self::assertEqualsCanonicalizing([21, 22], $ids('items_french', 'sac à doss', 'fallback'));
        // ... and so does the empty-result relaxation: "à" is never a word to keep or to report.
        $relax = ['relax_when_empty' => true, 'fuzzy_mode' => 'never'];
        $labels = array_column($fuzzphony->in('items_french')->query('à zzqqx')->thresholds($relax)->explain()->statements, 'label');
        self::assertSame(['full-text'], $labels, 'one word left: nothing to keep, no probe');
        $relaxed = $fuzzphony->in('items_french')->query('sac à zzqqx')->thresholds($relax)->get();
        self::assertEqualsCanonicalizing([21, 22], $relaxed->ids());
        self::assertContains('No results for all words; ignored words that match nothing: "zzqqx".', $relaxed->warnings);
    }

    public function testLanguagesWithoutAStopWordListGetNoStopWordDictionary(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame(['unaccent', 'romanian_stem'], $this->wordMapping('fuzzphony_romanian'));
        self::assertSame(['unaccent', 'simple'], $this->wordMapping('fuzzphony_simple'));
        self::assertFalse((bool) $this->connection->fetchValue("SELECT count(*) > 0 FROM pg_ts_dict WHERE dictname IN ('fuzzphony_romanian_stop', 'fuzzphony_simple_stop')"));
        self::assertSame([31], $fuzzphony->in('items_romanian')->query('masina')->get()->ids());
        self::assertSame([41], $fuzzphony->in('items_simple')->query('creme brulee')->get()->ids());
        self::assertSame(CheckStatus::Ok, $this->textConfigCheck($fuzzphony, 'items_romanian')->status);
    }

    public function testApplyingTwiceIsIdempotent(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->schema()->apply($this->connection);

        self::assertSame(['fuzzphony_german_stop', 'unaccent', 'german_stem'], $this->wordMapping('fuzzphony_german'));
        self::assertSame(1, Coerce::int($this->connection->fetchValue("SELECT count(*) FROM pg_ts_dict WHERE dictname = 'fuzzphony_german_stop'")));
        self::assertSame("'laptop':3 'tasch':1", $this->tsvector('fuzzphony_german', 'Tasche für Laptop'));
        self::assertSame(CheckStatus::Ok, $this->textConfigCheck($fuzzphony, 'items_german')->status);
    }

    public function testTheDoctorReportsAMissingConfiguration(): void
    {
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('items_german')->fromTable('fz_lang_item')->field('title')->language('german')->sync('manual')->build(),
        ]));
        $check = $this->textConfigCheck($fuzzphony, 'items_german');

        self::assertSame(CheckStatus::Error, $check->status);
        self::assertSame('"fuzzphony_german" is missing.', $check->message);
    }

    public function testApplyRepairsAConfigurationCreatedByVersion030(): void
    {
        // The statement 0.3.0 generated: unaccent straight in front of the stem dictionary.
        foreach (['german', 'hungarian', 'french'] as $language) {
            $this->connection->execute(sprintf('CREATE TEXT SEARCH CONFIGURATION "fuzzphony_%1$s" (COPY = "%1$s")', $language));
            $this->connection->execute(sprintf('ALTER TEXT SEARCH CONFIGURATION "fuzzphony_%1$s" ALTER MAPPING FOR hword, hword_part, word WITH "public".unaccent, "%1$s_stem"', $language));
        }
        self::assertSame("'fur':2 'laptop':3 'tasch':1", $this->tsvector('fuzzphony_german', 'Tasche für Laptop'), 'the bug being fixed');
        $registry = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([
            IndexDefinition::builder('items_german')->fromTable('fz_lang_item')->field('title')->language('german')->sync('manual')->build(),
        ]));
        $check = $this->textConfigCheck($registry, 'items_german');
        self::assertSame(CheckStatus::Error, $check->status);
        self::assertStringContainsString('keeps accented stop words', $check->message);
        self::assertSame('bin/console fuzzphony:schema --apply, then bin/console fuzzphony:reindex items_german', $check->fix);

        $fuzzphony = $this->fuzzphony();

        self::assertSame(['fuzzphony_german_stop', 'unaccent', 'german_stem'], $this->wordMapping('fuzzphony_german'));
        self::assertSame("'laptop':3 'tasch':1", $this->tsvector('fuzzphony_german', 'Tasche für Laptop'));
        self::assertSame("'billentyuz':3 'eger':1", $this->tsvector('fuzzphony_hungarian', 'Egér és billentyűzet'));
        self::assertSame("'dos':3 'sac':1", $this->tsvector('fuzzphony_french', 'Sac à dos'));
        self::assertSame(CheckStatus::Ok, $this->textConfigCheck($fuzzphony, 'items_german')->status);
    }

    private function textConfigCheck(Fuzzphony $fuzzphony, string $index): Check
    {
        foreach ($fuzzphony->inspect($index)->checks as $check) {
            if ($check->name === 'Text search configuration') {
                return $check;
            }
        }
        self::fail('no text search configuration check');
    }
}
