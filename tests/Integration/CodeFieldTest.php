<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\TestCase;

/** A `code: true` field finds every spelling of a code; the same data in a plain field does not. */
final class CodeFieldTest extends TestCase
{
    private Connection $connection;
    private Fuzzphony $fuzzphony;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_code_doc CASCADE');
        $this->connection->execute('DROP TABLE IF EXISTS fuzzphony_codes, fuzzphony_codes__next, fuzzphony_codes__changes, fuzzphony_plain_codes, fuzzphony_plain_codes__next, fuzzphony_plain_codes__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_code_doc (id bigint PRIMARY KEY, number text NOT NULL, title text NOT NULL, secret text NOT NULL DEFAULT \'\')');
        $rows = [
            [1, 'INV-001', 'First invoice'],
            [2, 'INV-2024-0042', 'Second invoice'],
            [3, 'AB-12/3', 'Odd one'],
            [4, 'INV.007', 'Dotted'],
            [5, 'INV-002', 'Third invoice'],
            [6, 'Müller-Ä12', 'Accents'],
        ];
        foreach ($rows as [$id, $number, $title]) {
            $this->connection->execute('INSERT INTO fz_code_doc (id, number, title) VALUES (:id, :n, :t)', ['id' => $id, 'n' => $number, 't' => $title]);
        }
        $indexes = [
            IndexDefinition::builder('codes')->fromQuery('SELECT id, number, title FROM fz_code_doc')->watch('fz_code_doc')
                ->field('number', 'A', code: true)->field('title', 'B')->language('english')->sync('manual')->build(),
            IndexDefinition::builder('plain_codes')->fromQuery('SELECT id, number, title FROM fz_code_doc')->watch('fz_code_doc')
                ->field('number', 'A')->field('title', 'B')->language('english')->sync('manual')->build(),
        ];
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry($indexes));
        $this->fuzzphony->schema()->apply($this->connection);
        $this->fuzzphony->reindex('codes');
        $this->fuzzphony->reindex('plain_codes');
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function codeQueries(): iterable
    {
        yield 'upper' => ['INV-001', [1]];
        yield 'lower' => ['inv-001', [1]];
        yield 'quoted' => ['"INV-001"', [1]];
        yield 'digits only' => ['001', [1]];
        yield 'long' => ['INV-2024-0042', [2]];
        yield 'slash' => ['AB-12/3', [3]];
        yield 'dot' => ['INV.007', [4]];
        yield 'dot as dash' => ['INV-007', [4]];
        yield 'without separators' => ['INV001', [1]];
        yield 'without separators long' => ['INV20240042', [2]];
        yield 'slash without' => ['ab123', [3]];
        yield 'scoped' => ['number:INV-001', [1]];
        yield 'scoped lower' => ['number:inv001', [1]];
        yield 'accented' => ['muller-a12', [6]];
        yield 'excluded' => ['inv -001 -2024 -007 -002', []];
        yield 'part' => ['0042', [2]];
        yield 'wrong' => ['INV-999', []];
    }

    /** @param list<int> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('codeQueries')]
    public function testEverySpellingFindsTheCode(string $query, array $expected): void
    {
        self::assertEqualsCanonicalizing($expected, $this->ids('codes', $query));
    }

    public function testThePlainFieldDoesNotFindTheHyphenatedCode(): void
    {
        self::assertSame([], $this->ids('plain_codes', 'INV-001'), 'the default parser indexes "-001" with its sign');
    }

    public function testTheWholeCodeRanksFirst(): void
    {
        self::assertSame([5], array_slice($this->ids('codes', 'inv-002'), 0, 1));
        $ids = $this->ids('codes', 'inv');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
    }

    public function testAPrefixOfACodeWord(): void
    {
        self::assertEqualsCanonicalizing([2], $this->ids('codes', '004*'));
    }

    public function testATypoInTheCodeStaysPerTheFuzzyFlag(): void
    {
        self::assertSame([], $this->ids('codes', 'INV-OO1'), 'the field is not fuzzy');
    }

    public function testTheHighlightMarksTheCodeInTheOriginalText(): void
    {
        $result = $this->fuzzphony->in('codes')->query('inv-001')->highlight('number')->get();
        self::assertCount(1, $result);
        foreach ($result as $hit) {
            self::assertSame('<mark>INV-001</mark>', $hit->highlights['number']);
        }
        self::assertCount(1, $this->fuzzphony->in('codes')->query('inv001')->highlight('number')->get());
        foreach ($this->fuzzphony->in('codes')->query('2024')->highlight('number')->get() as $hit) {
            self::assertStringContainsString('<mark>2024</mark>', $hit->highlights['number']);
            self::assertStringContainsString('INV-', $hit->highlights['number']);
        }
    }

    public function testTheFlagIsPartOfTheDefinitionHash(): void
    {
        $with = IndexDefinition::builder('x')->fromQuery('SELECT 1 AS id, 1 AS number')->watch('t')->field('number', 'A', code: true)->build();
        $without = IndexDefinition::builder('x')->fromQuery('SELECT 1 AS id, 1 AS number')->watch('t')->field('number', 'A')->build();
        self::assertNotSame(\Fuzzphony\Engine\Postgres\Schema\Fingerprint::definition($with), \Fuzzphony\Engine\Postgres\Schema\Fingerprint::definition($without));
    }

    /** @return list<int|string> */
    private function ids(string $index, string $query): array
    {
        return $this->fuzzphony->in($index)->query($query)->thresholds(['fuzzy_mode' => 'never'])->get()->ids();
    }
}
