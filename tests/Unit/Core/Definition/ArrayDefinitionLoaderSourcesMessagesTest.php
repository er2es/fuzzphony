<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\TestCase;

/** What the YAML/array loader reports for several sources and for the options of fields and filters: every problem, with its exact place. */
final class ArrayDefinitionLoaderSourcesMessagesTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private static function problems(array $config): string
    {
        try {
            (new ArrayDefinitionLoader())->load('site', $config);
        } catch (InvalidDefinition $e) {
            return $e->getMessage();
        }
        self::fail('InvalidDefinition expected');
    }

    public function testAStringWatchOfASourceIsItsIdsQuery(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('site', [
            'sources' => ['a' => ['table' => 'a_table', 'watch' => ['t' => 'SELECT id FROM a_table WHERE t_id = :id']]],
            'fields' => ['title' => 'A'],
        ]);

        self::assertSame('SELECT id FROM a_table WHERE t_id = :id', $definition->sources[0]->watches[0]->affectedIds);
    }

    public function testASourceWithoutOptionsReadsTheTableOfItsName(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('site', ['sources' => ['a' => [], 'b' => ['table' => 'other']], 'fields' => ['title' => 'A'], 'sync' => 'manual']);

        self::assertSame(['a', 'other'], array_map(static fn($s): ?string => $s->source->table, $definition->sources));
    }

    public function testASourceNameThatIsNotAnIdentifierIsADefinitionProblemNotAnError(): void
    {
        self::assertStringContainsString('Source name "123" must match', self::problems(['sources' => ['123' => ['table' => 'a']], 'fields' => ['title' => 'A'], 'sync' => 'manual']));
    }

    public function testSourcesExcludeTheOptionsThatBelongInsideEachSource(): void
    {
        $message = self::problems(['source' => ['table' => 'x'], 'watch' => ['t' => 'SELECT :id'], 'sources' => ['a' => ['table' => 'y']], 'fields' => ['title' => 'A']]);

        self::assertStringContainsString('"sources" and "source" are exclusive: with several sources, "source" belongs inside each source (an index has one "source" or several "sources").', $message);
        self::assertStringContainsString('"sources" and "watch" are exclusive: with several sources, "watch" belongs inside each source.', $message);
    }

    public function testSourcesMustBeANonEmptyMap(): void
    {
        self::assertStringContainsString('"sources" must be a map of source name => { query | query_file | table, watch, entity, id_type }', self::problems(['sources' => [], 'fields' => ['title' => 'A']]));
        self::assertStringContainsString('"sources" must be a map of source name', self::problems(['sources' => ['a', 'b'], 'fields' => ['title' => 'A']]));
    }

    public function testASourceMayBeAnEmptyListButNotAFilledOne(): void
    {
        self::assertStringContainsString('Source "a" must be a map: { table: product } or { query: "SELECT ..." }, got a list.', self::problems(['sources' => ['a' => ['x']], 'fields' => ['title' => 'A']]));
    }

    public function testEverySourceIsCheckedNotOnlyTheFirstBadOne(): void
    {
        $message = self::problems(['sources' => ['a' => 'x', 'b' => 5, 'c' => ['entity' => 'No\\Such']], 'fields' => ['title' => 'A']]);

        self::assertStringContainsString('Source "a" must be a map', $message);
        self::assertStringContainsString('Source "b" must be a map', $message);
        self::assertStringContainsString('Source "c": "entity" must be the name of an existing class', $message);
    }

    public function testTheWatchOfASourceIsCheckedLikeTheWatchOfAnIndex(): void
    {
        self::assertStringContainsString('"watch" must be a map of watch name => a query returning the affected ids or options (e.g. watch: { product: "SELECT :id" }), got a list.', self::problems(['sources' => ['a' => ['table' => 'x', 'watch' => ['SELECT :id']]], 'fields' => ['title' => 'A']]));
    }

    public function testTheReturnableColumnsAreAListOfNames(): void
    {
        $expected = 'Source "a": "returnable" must be a list of source column names (e.g. returnable: [title, slug, price])';

        self::assertStringContainsString($expected, self::problems(['sources' => ['a' => ['table' => 'x', 'returnable' => ['k' => 'title']]], 'fields' => ['title' => 'A']]));
        self::assertStringContainsString($expected, self::problems(['sources' => ['a' => ['table' => 'x', 'returnable' => ['title', 5]]], 'fields' => ['title' => 'A']]));
        self::assertStringContainsString($expected, self::problems(['sources' => ['a' => ['table' => 'x', 'returnable' => 'title']], 'fields' => ['title' => 'A']]));
    }

    public function testASourceThatIsNotAMapStopsAtThatMessage(): void
    {
        self::assertSame(
            "Search index \"site\" is misconfigured:\n  - \"source\" must be a map: { table: product } or { query: \"SELECT ...\" }, got 'article'.",
            self::problems(['source' => 'article', 'fields' => ['title' => 'A']]),
        );
    }

    public function testEveryBadFieldIsReportedWithItsOptionName(): void
    {
        $message = self::problems(['source' => ['table' => 't'], 'fields' => ['a' => 5, 'title' => ['weight' => 'A', 'fuzzy' => 'yes', 'highlight' => 'no', 'code' => 'maybe'], 'c' => 7]]);

        self::assertStringContainsString('Field "a" must be a weight letter', $message);
        self::assertStringContainsString('Field "title" "fuzzy" must be true or false (a boolean, not a string), got \'yes\'.', $message);
        self::assertStringContainsString('Field "title" "highlight" must be true or false', $message);
        self::assertStringContainsString('Field "title" "code" must be true or false (a boolean, not a string), got \'maybe\'.', $message);
        self::assertStringContainsString('Field "c" must be a weight letter', $message);
    }

    public function testTheManyOptionsAreCheckedWithTheirPlace(): void
    {
        $message = self::problems(['source' => ['table' => 't'], 'fields' => ['title' => 'A', 'city' => ['weight' => 'C', 'many' => ['key' => 'k', 'column' => 'c', 'colum' => 'x', 'id' => 5]]]]);

        self::assertStringContainsString('Field "city" "many": unknown option(s): colum. Allowed: from, key, column, limit, id, rank.', $message);
        self::assertStringContainsString('Field "city" "many" needs "from" (a non-empty string), e.g. many: { from: address, key: customer_id, column: city }.', $message);
        self::assertStringNotContainsString('needs "key"', $message);
        self::assertStringContainsString('Field "city" "many" "id" must be the primary key column of the child table (a non-empty string), got 5.', $message);
    }

    public function testAManyIdThatIsAStringIsAccepted(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('site', ['source' => ['table' => 't'], 'fields' => ['title' => 'A', 'city' => ['weight' => 'C', 'many' => ['from' => 'a', 'key' => 'k', 'column' => 'c', 'id' => 'pk']]], 'sync' => 'manual']);

        self::assertSame('pk', $definition->fields[1]->many?->id);
    }

    public function testASourceEntityOfAnExistingClassIsKept(): void
    {
        $definition = (new ArrayDefinitionLoader())->load('site', ['sources' => ['a' => ['table' => 'x', 'entity' => Product::class]], 'fields' => ['title' => 'A'], 'sync' => 'manual']);

        self::assertSame(Product::class, $definition->sources[0]->entityClass);
    }

    public function testASourceMayReadItsQueryFromAFile(): void
    {
        $dir = sys_get_temp_dir() . '/fz-qf-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/a.sql', 'SELECT id, title FROM a_table');
        try {
            $definition = (new ArrayDefinitionLoader($dir))->load('site', ['sources' => ['a' => ['query_file' => 'a.sql', 'watch' => ['a_table' => 'SELECT :id']]], 'fields' => ['title' => 'A']]);
        } finally {
            unlink($dir . '/a.sql');
            rmdir($dir);
        }

        self::assertSame('SELECT id, title FROM a_table', $definition->sources[0]->source->query);
    }
}
