<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Core\Wizard\ForeignKey;
use Fuzzphony\Core\Wizard\Suggestion;
use Fuzzphony\Core\Wizard\TableProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins the thresholds, notes and generated SQL of the suggester's heuristics. */
final class DefinitionSuggesterHeuristicsTest extends TestCase
{
    private static function id(): ColumnProfile
    {
        return new ColumnProfile('id', ColumnKind::Int, 'bigint');
    }

    /** @param list<ColumnProfile> $columns */
    private static function suggest(array $columns, int $rows = 0, string $table = 'article'): Suggestion
    {
        return (new DefinitionSuggester())->suggest(new TableProfile($table, [self::id(), ...$columns], 'id', $rows));
    }

    private static function definition(Suggestion $suggestion): IndexDefinition
    {
        self::assertNotNull($suggestion->definition, implode("\n", $suggestion->notes));

        return $suggestion->definition;
    }

    /** @return array<string, string> column => "role: reason" */
    private static function decisions(Suggestion $suggestion): array
    {
        $out = [];
        foreach ($suggestion->decisions as $decision) {
            $out[$decision->column] = $decision->role . ': ' . $decision->reason;
        }

        return $out;
    }

    private static function title(): ColumnProfile
    {
        return new ColumnProfile('title', ColumnKind::Text, 'text', false, 48);
    }

    public function testTableNotesSurviveEveryEarlyExit(): void
    {
        $suggester = new DefinitionSuggester();
        $text = [new ColumnProfile('title', ColumnKind::Text, 'text')];

        $unsafeTable = $suggester->suggest(new TableProfile('bad$table', $text, 'title', 0, [], ['sampled']));
        self::assertSame('sampled', $unsafeTable->notes[0]);
        self::assertSame('Table "bad$table" name is not a plain identifier ([A-Za-z_][A-Za-z0-9_]*), so it is never put into generated SQL.', $unsafeTable->notes[1]);

        $unsafeKey = $suggester->suggest(new TableProfile('article', $text, 'ti tle', 0, [], ['sampled']));
        self::assertSame('sampled', $unsafeKey->notes[0]);
        self::assertSame('Primary key "ti tle" name is not a plain identifier ([A-Za-z_][A-Za-z0-9_]*), so it is never put into generated SQL.', $unsafeKey->notes[1]);

        $noKey = $suggester->suggest(new TableProfile('article', $text, null, 0, [], ['sampled']));
        self::assertSame('sampled', $noKey->notes[0]);
        self::assertSame('Table "article" has no single-column primary key; Fuzzphony needs one stable id per document.', $noKey->notes[1]);

        $noText = $suggester->suggest(new TableProfile('article', [self::id()], 'id', 0, [], ['sampled']));
        self::assertSame(['sampled', 'No text column looks searchable. Pick fields manually.'], $noText->notes);
    }

    public function testEveryViolationOfAnInvalidDefinitionIsReportedAfterTheNotes(): void
    {
        // "Title" and "title" normalise to the same field name; the language name is invalid as well
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            self::id(),
            new ColumnProfile('title', ColumnKind::Text, 'text', false, 48),
            new ColumnProfile('Title', ColumnKind::Text, 'text', false, 48),
        ], 'id', 0, [], ['sampled']), null, 'not a valid language!');

        self::assertNull($suggestion->definition);
        self::assertSame('sampled', $suggestion->notes[0]);
        self::assertGreaterThanOrEqual(3, count($suggestion->notes));
        self::assertStringContainsString('Field "title" is defined twice', implode("\n", $suggestion->notes));
        self::assertStringContainsString('must be a text search configuration name', implode("\n", $suggestion->notes));
    }

    public function testColumnsAfterSkippedOnesAreStillClassified(): void
    {
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            new ColumnProfile('bad col', ColumnKind::Text, 'text'),
            new ColumnProfile('password', ColumnKind::Text, 'text'),
            new ColumnProfile('slug', ColumnKind::Text, 'text'),
            new ColumnProfile('author_id', ColumnKind::Int, 'bigint'),
            self::id(),
            self::title(),
            new ColumnProfile('tail', ColumnKind::Text, 'text', false, 20),
        ], 'id', 0, [new ForeignKey('author_id', 'author', 'id')]));
        $index = self::definition($suggestion);

        self::assertSame(['title', 'tail'], array_map(static fn($f): string => $f->name, $index->fields));
        $decisions = self::decisions($suggestion);
        self::assertSame('skip: looks sensitive; never indexed automatically', $decisions['password']);
        self::assertSame('skip: technical column', $decisions['slug']);
        self::assertSame('filter: foreign key: filter by relation, label searched via join', $decisions['author_id']);
        self::assertSame('id: primary key (bigint)', $decisions['id']);
    }

    public function testEveryColumnKindGetsItsFilterTypeAndExplanation(): void
    {
        $suggestion = self::suggest([
            self::title(),
            new ColumnProfile('stock', ColumnKind::Int, 'integer'),
            new ColumnProfile('price', ColumnKind::Float, 'numeric'),
            new ColumnProfile('is_new', ColumnKind::Bool, 'boolean'),
            new ColumnProfile('released', ColumnKind::Date, 'date'),
            new ColumnProfile('stamped', ColumnKind::DateTime, 'timestamp'),
            new ColumnProfile('kind', ColumnKind::Text, 'text', false, 8, 5),
            new ColumnProfile('payload', ColumnKind::Other, 'jsonb'),
        ], 1_000);
        $index = self::definition($suggestion);

        self::assertSame(FilterType::Int, $index->filter('stock')->type);
        self::assertSame(FilterType::Float, $index->filter('price')->type);
        self::assertSame(FilterType::Bool, $index->filter('is_new')->type);
        self::assertSame(FilterType::Date, $index->filter('released')->type);
        self::assertSame(FilterType::DateTime, $index->filter('stamped')->type);
        self::assertSame(FilterType::String, $index->filter('kind')->type);

        $decisions = self::decisions($suggestion);
        self::assertSame('filter: number: range filters (price, stock, ...)', $decisions['stock']);
        self::assertSame('filter: number: range filters (price, stock, ...)', $decisions['price']);
        self::assertSame('filter: yes/no flag', $decisions['is_new']);
        self::assertSame('filter: date: range filters', $decisions['released']);
        self::assertSame('filter: date: range filters', $decisions['stamped']);
        self::assertSame('filter: only ~5 distinct values: better as a filter (status, type, ...)', $decisions['kind']);
        self::assertSame('skip: type jsonb is not searchable', $decisions['payload']);
        self::assertSame('field A, fuzzy: looks like the title: most important, typo tolerant', $decisions['title']);
    }

    public function testRecencyAcceptsDatesAndIgnoresOtherTypes(): void
    {
        $date = self::suggest([self::title(), new ColumnProfile('date', ColumnKind::Date, 'date')]);
        self::assertSame('date', self::definition($date)->recencyColumn);
        self::assertSame('recency: newest first in the "popular" ranking profile', self::decisions($date)['date']);

        $text = self::suggest([self::title(), new ColumnProfile('created', ColumnKind::Text, 'text', false, 10)]);
        self::assertNull(self::definition($text)->recencyColumn);
        self::assertNull(self::definition($text)->boostColumn);
    }

    public function testTheFirstTitleWinsAndLaterColumnsAreNotTitles(): void
    {
        $suggestion = self::suggest([
            new ColumnProfile('teaser', ColumnKind::Text, 'text', false, 40),
            new ColumnProfile('name', ColumnKind::Text, 'text', false, 20),
            new ColumnProfile('title', ColumnKind::Text, 'text', false, 20),
        ]);
        $index = self::definition($suggestion);

        self::assertSame(Weight::B, $index->field('teaser')?->weight, 'no title seen yet: a short text is not a title');
        self::assertSame(Weight::A, $index->field('name')?->weight);
        self::assertSame(Weight::B, $index->field('title')?->weight, 'only one title');
        self::assertTrue($index->field('name')->fuzzy);
        self::assertFalse($index->field('teaser')->fuzzy);
        self::assertSame('field B: short text', self::decisions($suggestion)['teaser']);
    }

    public function testCodeColumnsAreExactNotFuzzy(): void
    {
        $suggestion = self::suggest([self::title(), new ColumnProfile('product_code', ColumnKind::Text, 'text', false, 500, 99_999)]);

        self::assertSame('field B: identifier / code: exact and prefix matches matter, typos do not', self::decisions($suggestion)['product_code']);
        self::assertSame(Weight::B, self::definition($suggestion)->field('product_code')?->weight);
    }

    /** @return iterable<string, array{float|null, float|null, int, bool}> */
    public static function lowCardinalityBoundaries(): iterable
    {
        yield '50 distinct values, 200 rows, length 30: filter' => [50.0, 30.0, 200, true];
        yield '51 distinct values: field' => [51.0, 30.0, 200, false];
        yield '199 rows: field' => [50.0, 30.0, 199, false];
        yield 'average length 31: field' => [50.0, 31.0, 200, false];
        yield 'unknown length: filter' => [50.0, null, 200, true];
        yield 'unknown distinct count: field' => [null, 10.0, 5_000, false];
    }

    #[DataProvider('lowCardinalityBoundaries')]
    public function testLowCardinalityTextBecomesAFilterOnlyWithinTheBoundaries(?float $distinct, ?float $length, int $rows, bool $filter): void
    {
        $index = self::definition(self::suggest([self::title(), new ColumnProfile('colour', ColumnKind::Text, 'text', false, $length, $distinct)], $rows));

        if ($filter) {
            self::assertSame(FilterType::String, $index->filter('colour')->type);
            self::assertNull($index->field('colour'));
        } else {
            self::assertNotNull($index->field('colour'));
            self::assertSame([], $index->filters);
        }
    }

    /** @return iterable<string, array{string, float|null, string}> */
    public static function textLengths(): iterable
    {
        yield 'long by name, unknown length' => ['description', null, 'D'];
        yield 'long by name, short values' => ['notes', 10.0, 'D'];
        yield 'long by length' => ['remark', 201.0, 'D'];
        yield 'exactly 200 is not long' => ['remark', 200.0, 'C'];
        yield 'exactly 60 is short' => ['remark', 60.0, 'B'];
        yield 'above 60 is medium' => ['remark', 61.0, 'C'];
        yield 'unknown length is medium' => ['remark', null, 'C'];
    }

    #[DataProvider('textLengths')]
    public function testTextWeightFollowsNameAndAverageLength(string $name, ?float $length, string $weight): void
    {
        $index = self::definition(self::suggest([self::title(), new ColumnProfile($name, ColumnKind::Text, 'text', false, $length)]));

        self::assertSame(Weight::from($weight), $index->field($name)?->weight);
        self::assertFalse($index->field($name)->fuzzy, 'only the title is typo tolerant');
    }

    public function testFilterColumnsKeepTheirOriginalNameWhenNormalised(): void
    {
        $index = self::definition(self::suggest([
            self::title(),
            new ColumnProfile('IsActive', ColumnKind::Bool, 'boolean'),
            new ColumnProfile('stock', ColumnKind::Int, 'integer'),
            new ColumnProfile('_9lives', ColumnKind::Int, 'integer'),
        ]));

        self::assertSame('IsActive', $index->filter('is_active')->column);
        self::assertNull($index->filter('stock')->column);
        self::assertSame('_9lives', $index->filter('c_9lives')->column, 'a name that does not start with a letter gets a c_ prefix');
    }

    public function testIndexAndRelationNamesAreLowerCased(): void
    {
        $author = new TableProfile('Author', [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('Article', [
            self::id(),
            self::title(),
            new ColumnProfile('writer', ColumnKind::Int, 'bigint'),
        ], 'id', 0, [new ForeignKey('writer', 'Author', 'id', $author)]));
        $index = self::definition($suggestion);

        self::assertSame('articles', $index->name);
        self::assertNotNull($index->field('author'), 'the relation field is named after the referenced table, lower-cased');

        $schemaQualified = self::definition(self::suggest([self::title()], 0, 'Shop.Item'));
        self::assertSame('items', $schemaQualified->name);
    }

    public function testRelationFieldsAreNamedAfterTheForeignKeyColumn(): void
    {
        $brand = new TableProfile('maker', [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $index = self::definition((new DefinitionSuggester())->suggest(new TableProfile('product', [
            self::id(),
            new ColumnProfile('Brand_id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('summary', ColumnKind::Text, 'text', false, 40),
        ], 'id', 0, [new ForeignKey('Brand_id', 'maker', 'id', $brand)])));

        self::assertNotNull($index->field('brand'));
    }

    public function testIdTypeFollowsThePrimaryKeyColumn(): void
    {
        $suggester = new DefinitionSuggester();
        $title = self::title();

        foreach ([[ColumnKind::Int, IdType::Int], [ColumnKind::Uuid, IdType::Uuid], [ColumnKind::Text, IdType::String]] as [$kind, $expected]) {
            $suggestion = $suggester->suggest(new TableProfile('article', [new ColumnProfile('id', $kind, 'x'), $title], 'id'));
            self::assertSame($expected, self::definition($suggestion)->idType);
        }

        $unknownKey = $suggester->suggest(new TableProfile('article', [$title], 'id'));
        self::assertSame(IdType::String, self::definition($unknownKey)->idType, 'a primary key that is not among the columns');
    }

    public function testTheLargeTableNoteStartsAboveFiveMillionRows(): void
    {
        $note = 'Large table: run the first reindex off-peak; queue sync keeps writes cheap afterwards.';

        self::assertNotContains($note, self::suggest([self::title()], 5_000_000)->notes);
        self::assertContains($note, self::suggest([self::title()], 5_000_001)->notes);
    }

    /** @return iterable<string, array{float, float}> */
    public static function boostRanges(): iterable
    {
        yield 'rounds up' => [80.0, 0.004];       // 0.3 / 80 = 0.00375
        yield 'rounds down' => [90.0, 0.003];     // 0.3 / 90 = 0.00333
        yield 'exact' => [60_000.0, 0.000005];
    }

    #[DataProvider('boostRanges')]
    public function testBoostWeightIsRoundedToOneSignificantDigit(float $max, float $expected): void
    {
        $index = self::definition(self::suggest([self::title(), new ColumnProfile('views', ColumnKind::Int, 'integer', false, null, null, $max)]));

        self::assertEqualsWithDelta($expected, $index->profile('popular')->boost, 1e-12);
        self::assertSame(0.0, $index->profile('popular')->recency);
    }

    public function testAZeroMaximumFallsBackToTheGuessedBoost(): void
    {
        $suggestion = self::suggest([self::title(), new ColumnProfile('views', ColumnKind::Int, 'integer', false, null, null, 0.0)]);

        self::assertSame(0.01, self::definition($suggestion)->profile('popular')->boost);
        self::assertStringContainsString('Could not estimate the range of "views"', implode("\n", $suggestion->notes));
    }

    public function testRecencyAloneGetsAPopularProfile(): void
    {
        $index = self::definition(self::suggest([self::title(), new ColumnProfile('created_at', ColumnKind::DateTime, 'timestamp')]));

        self::assertSame(0.0, $index->profile('popular')->boost);
        self::assertSame(0.3, $index->profile('popular')->recency);
        self::assertNull($index->boostColumn);
    }

    public function testJoinsAreLimitedToThreeAndTheLabelMustBeUnambiguous(): void
    {
        $labelled = static fn(string $name): TableProfile => new TableProfile($name, [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $single = new TableProfile('plain', [self::id(), new ColumnProfile('caption', ColumnKind::Text, 'text')], 'id');
        $ambiguous = new TableProfile('vague', [self::id(), new ColumnProfile('one', ColumnKind::Text, 'text'), new ColumnProfile('two', ColumnKind::Text, 'text')], 'id');

        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            self::id(),
            self::title(),
            new ColumnProfile('vague_id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('bad_id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('plain_id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('a_id', ColumnKind::Int, 'bigint'),
        ], 'id', 0, [
            new ForeignKey('vague_id', 'vague', 'id', $ambiguous),
            new ForeignKey('bad_id', 'bad$table', 'id', $labelled('bad$table')),
            new ForeignKey('plain_id', 'plain', 'id', $single),
            new ForeignKey('a_id', 'a', 'id', $labelled('a')),
        ]));
        $index = self::definition($suggestion);
        $query = (string) $index->source->query;

        self::assertNotNull($index->field('plain'), 'a single text column is the label, even when it is not the first column');
        self::assertNull($index->field('vague'), 'two text columns and none called name: ambiguous, no join');
        self::assertNull($index->field('a'), 'at most three foreign keys are considered');
        self::assertStringContainsString('LEFT JOIN "plain" j2 ON j2."id" = t."plain_id"', $query, 'rejected relations before it do not stop the next one');
        self::assertStringNotContainsString('"a"', $query);
        self::assertSame('skip: not joined: "bad$table" is not a plain identifier, so it is never put into generated SQL', self::decisions($suggestion)['bad_id']);
    }

    public function testAnUnsafeReferencedColumnIsNeverJoined(): void
    {
        $author = new TableProfile('author', [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            self::id(),
            self::title(),
            new ColumnProfile('author_id', ColumnKind::Int, 'bigint'),
        ], 'id', 0, [new ForeignKey('author_id', 'author', 'id; --', $author)]));
        $index = self::definition($suggestion);

        self::assertNull($index->source->query);
        self::assertSame('skip: not joined: "id; --" is not a plain identifier, so it is never put into generated SQL', self::decisions($suggestion)['author_id']);
    }

    public function testTheJoinedQueryIsGeneratedWithStableAliases(): void
    {
        $author = new TableProfile('author', [self::id(), new ColumnProfile('full_name', ColumnKind::Text, 'text')], 'id');
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            self::id(),
            new ColumnProfile('teaser', ColumnKind::Text, 'text', false, 40),
            new ColumnProfile('Body', ColumnKind::Text, 'text', false, 500),
            new ColumnProfile('status', ColumnKind::Text, 'text', false, 8, 3),
            new ColumnProfile('author_id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('IsActive', ColumnKind::Bool, 'boolean'),
            new ColumnProfile('price', ColumnKind::Float, 'numeric'),
            new ColumnProfile('views', ColumnKind::Int, 'integer', false, null, null, 100.0),
            new ColumnProfile('created_at', ColumnKind::DateTime, 'timestamp'),
        ], 'id', 1_000, [new ForeignKey('author_id', 'author', 'id', $author)]));
        $index = self::definition($suggestion);

        self::assertSame(
            "SELECT t.\"id\", t.\"teaser\", t.\"Body\" AS \"body\", j0.\"full_name\" AS \"author\", t.\"status\", t.\"author_id\", t.\"IsActive\" AS \"is_active\", t.\"price\", t.\"created_at\", t.\"views\"\nFROM \"article\" t\nLEFT JOIN \"author\" j0 ON j0.\"id\" = t.\"author_id\"",
            (string) $index->source->query,
        );
    }

    public function testJoinedLabelsAreTypoTolerantAndExplained(): void
    {
        $author = new TableProfile('author', [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [
            self::id(),
            new ColumnProfile('author_id', ColumnKind::Int, 'bigint'),
        ], 'id', 0, [new ForeignKey('author_id', 'author', 'id', $author)]));
        $index = self::definition($suggestion);

        self::assertTrue($index->field('author')?->fuzzy);
        self::assertSame(Weight::A, $index->field('author')->weight, 'no title column: the related label is the main field');
        self::assertSame('join author.name -> field author: related label (brand, category, author, ...) is searchable; changes in that table are watched', self::decisions($suggestion)['author_id']);
    }

    public function testAPopularityColumnIsExplainedAsTheBoost(): void
    {
        $suggestion = self::suggest([self::title(), new ColumnProfile('views', ColumnKind::Int, 'integer')]);

        self::assertSame('boost: popularity-like number: used by the "popular" ranking profile', self::decisions($suggestion)['views']);
    }

    public function testAForeignKeyColumnWithAnUnsafeNameIsNotJoined(): void
    {
        $author = new TableProfile('author', [self::id(), new ColumnProfile('name', ColumnKind::Text, 'text')], 'id');
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('article', [self::id(), self::title()], 'id', 0, [new ForeignKey('author id; --', 'author', 'id', $author)]));

        self::assertNull(self::definition($suggestion)->source->query);
        self::assertSame('skip: not joined: "author id; --" is not a plain identifier, so it is never put into generated SQL', self::decisions($suggestion)['author id; --']);
    }
}
