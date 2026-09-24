<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Wizard\ColumnKind;
use Fuzzphony\Core\Wizard\ColumnProfile;
use Fuzzphony\Core\Wizard\Decision;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Core\Wizard\ForeignKey;
use Fuzzphony\Core\Wizard\TableProfile;
use PHPUnit\Framework\TestCase;

final class DefinitionSuggesterTest extends TestCase
{
    /** @param list<ForeignKey> $foreignKeys */
    private static function article(array $foreignKeys = []): TableProfile
    {
        return new TableProfile('article', [
            new ColumnProfile('id', ColumnKind::Uuid, 'uuid', false),
            new ColumnProfile('title', ColumnKind::Text, 'text', false, 48, 90_000),
            new ColumnProfile('Body', ColumnKind::Text, 'text', true, 2_400, 90_000),
            new ColumnProfile('sku', ColumnKind::Text, 'character varying(32)', true, 12, 90_000),
            new ColumnProfile('status', ColumnKind::Text, 'text', false, 8, 4),
            new ColumnProfile('password_hash', ColumnKind::Text, 'text', true, 60, 90_000),
            new ColumnProfile('author_id', ColumnKind::Int, 'bigint', false, 8, 300, 300),
            new ColumnProfile('view_count', ColumnKind::Int, 'integer', false, 4, 5_000, 60_000),
            new ColumnProfile('is_draft', ColumnKind::Bool, 'boolean'),
            new ColumnProfile('created_at', ColumnKind::DateTime, 'timestamp with time zone'),
            new ColumnProfile('published_at', ColumnKind::DateTime, 'timestamp with time zone'),
            new ColumnProfile('metadata', ColumnKind::Other, 'jsonb'),
        ], 'id', 100_000, $foreignKeys);
    }

    public function testClassifiesColumnsAndExplainsEveryDecision(): void
    {
        $suggestion = (new DefinitionSuggester())->suggest(self::article());
        $index = $suggestion->definition;
        self::assertNotNull($index, implode("\n", $suggestion->notes));

        self::assertSame('articles', $index->name);
        self::assertSame('article', $index->source->table);
        self::assertSame(IdType::Uuid, $index->idType);
        self::assertSame(Weight::A, $index->field('title')?->weight);
        self::assertTrue($index->field('title')->fuzzy);
        self::assertSame(Weight::D, $index->field('body')?->weight);
        self::assertSame('Body', $index->field('body')->column, 'original column name is kept');
        self::assertSame(Weight::B, $index->field('sku')?->weight);
        self::assertFalse($index->field('sku')->fuzzy);
        self::assertNull($index->field('password_hash'));
        self::assertSame(FilterType::String, $index->filter('status')->type, 'low-cardinality text becomes a filter');
        self::assertSame(FilterType::Bool, $index->filter('is_draft')->type);
        self::assertSame('view_count', $index->boostColumn);
        self::assertSame('published_at', $index->recencyColumn, 'published_at wins over created_at');
        self::assertEqualsWithDelta(0.000005, $index->profile('popular')->boost, 1e-9, '0.3 / 60 000, rounded');

        $roles = [];
        foreach ($suggestion->decisions as $decision) {
            $roles[$decision->column][] = $decision->role;
        }
        self::assertSame(['skip'], $roles['password_hash']);
        self::assertSame(['skip'], $roles['metadata']);
        self::assertContainsOnlyInstancesOf(Decision::class, $suggestion->decisions);
    }

    public function testJoinsRelatedLabelsAndWatchesThem(): void
    {
        $author = new TableProfile('author', [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('full_name', ColumnKind::Text, 'text'),
            new ColumnProfile('email', ColumnKind::Text, 'text'),
        ], 'id');
        $index = (new DefinitionSuggester())->suggest(self::article([new ForeignKey('author_id', 'author', 'id', $author)]))->definition;
        self::assertNotNull($index);

        self::assertStringContainsString('LEFT JOIN "author" j0 ON j0."id" = t."author_id"', (string) $index->source->query);
        self::assertStringContainsString('j0."full_name" AS "author"', (string) $index->source->query);
        self::assertSame(Weight::B, $index->field('author')?->weight);
        self::assertSame(FilterType::Int, $index->filter('author_id')->type);
        self::assertSame(['article', 'author'], array_map(static fn($w): string => $w->table, $index->watches));
        self::assertSame('SELECT "id" FROM "article" WHERE "author_id" = :id', $index->watches[1]->affectedIds);
    }

    public function testNoPrimaryKeyMeansNoSuggestion(): void
    {
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('log', [new ColumnProfile('message', ColumnKind::Text, 'text')], null));

        self::assertNull($suggestion->definition);
        self::assertStringContainsString('primary key', $suggestion->notes[0]);
    }

    public function testNoTextMeansNoSuggestion(): void
    {
        $suggestion = (new DefinitionSuggester())->suggest(new TableProfile('metric', [
            new ColumnProfile('id', ColumnKind::Int, 'bigint'),
            new ColumnProfile('value', ColumnKind::Float, 'double precision'),
        ], 'id'));

        self::assertNull($suggestion->definition);
        self::assertSame('No text column looks searchable. Pick fields manually.', $suggestion->notes[0]);
    }
}
