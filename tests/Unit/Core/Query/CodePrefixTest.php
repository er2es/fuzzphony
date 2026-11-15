<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Query\CodePrefix;
use Fuzzphony\Core\Query\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodePrefixTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function texts(): iterable
    {
        yield 'one code' => ['ORD-2025-00003', 'number:ORD-2025-00003* | tax_no:ORD-2025-00003*'];
        yield 'the last word only' => ['kovacs ORD202500003', 'kovacs (number:ORD202500003* | tax_no:ORD202500003*)'];
        yield 'scoped to a code' => ['number:ORD-2025-00003', 'number:ORD-2025-00003*'];
        yield 'scoped to a plain field' => ['customer:kova', null];
        yield 'a prefix already' => ['ORD-2025*', null];
        yield 'excluded' => ['ord -2025', null];
        yield 'a phrase' => ['"ord 2025"', null];
        yield 'a group' => ['ord (2025 | 2026)', null];
        yield 'no word' => ['***', null];
        yield 'nothing' => ['', null];
    }

    #[DataProvider('texts')]
    public function testTheLastWordBecomesAPrefixOnTheCodeFields(string $text, ?string $to): void
    {
        $index = self::index();
        $rescue = CodePrefix::rescue($index, new SearchQuery($text), $index->thresholds);

        self::assertSame($to, $rescue['to'] ?? null);
    }

    public function testFromIsTheTypedQuery(): void
    {
        $index = self::index();

        self::assertSame(['from' => 'kovacs ORD-2025-00003', 'to' => 'kovacs (number:ORD-2025-00003* | tax_no:ORD-2025-00003*)'], CodePrefix::rescue($index, new SearchQuery('kovacs ORD-2025-00003'), $index->thresholds));
    }

    public function testOnlyTheCodeFieldsTheViewerMaySearch(): void
    {
        $index = self::index();

        self::assertSame('number:ORD-2025*', CodePrefix::rescue($index, new SearchQuery('ORD-2025', allowedFields: ['number', 'customer']), $index->thresholds)['to'] ?? null);
        self::assertNull(CodePrefix::rescue($index, new SearchQuery('ORD-2025', allowedFields: ['customer']), $index->thresholds));
        self::assertNull(CodePrefix::rescue($index, new SearchQuery('tax_no:ORD-2025', allowedFields: ['number']), $index->thresholds));
    }

    public function testNeverWithoutACodeFieldForSearchAsYouTypeOrWhenSwitchedOff(): void
    {
        $index = self::index();
        $plain = IndexDefinition::builder('p')->fromTable('t')->field('name', 'A')->build();

        self::assertNull(CodePrefix::rescue($plain, new SearchQuery('ORD-2025'), $plain->thresholds));
        self::assertNull(CodePrefix::rescue($index, new SearchQuery('ORD-2025', asYouType: true), $index->thresholds));
        $off = $index->thresholds->with(['rescue' => ['correct_when_empty' => false]]);
        self::assertNull(CodePrefix::rescue($index, new SearchQuery('ORD-2025'), $off));
    }

    private static function index(): IndexDefinition
    {
        return IndexDefinition::builder('orders')->fromTable('t')->field('number', 'A', code: true)->field('customer', 'B')->field('tax_no', 'A', code: true)->build();
    }
}
