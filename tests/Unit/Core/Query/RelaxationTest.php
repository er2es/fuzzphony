<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\Relaxation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelaxationTest extends TestCase
{
    private static function parse(string $text): Node
    {
        $root = (new QueryParser())->parse($text)->root;
        self::assertNotNull($root);

        return $root;
    }

    /** @return iterable<string, array{string, list<string>, string|null}> */
    public static function removals(): iterable
    {
        yield 'and' => ['wireless mouse aluminum', ['aluminum'], '(wireless AND mouse)'];
        yield 'two of three' => ['wireless mouse aluminum', ['wireless', 'aluminum'], 'mouse'];
        yield 'or keeps the other branch' => ['(foo | mouse) wireless', ['foo'], '(mouse AND wireless)'];
        yield 'a whole or group' => ['(foo | bar) wireless', ['foo', 'bar'], 'wireless'];
        yield 'nested' => ['wireless (mouse | (foo bar))', ['foo'], '(wireless AND (mouse OR bar))'];
        yield 'phrase' => ['"usb receiver" mouse', ['"usb receiver"'], 'mouse'];
        yield 'field scoped' => ['name:foo mouse', ['name:foo'], 'mouse'];
        yield 'prefix' => ['alu* mouse', ['alu*'], 'mouse'];
        yield 'not untouched' => ['mouse foo -cable', ['foo'], '(mouse AND NOT cable)'];
        yield 'nothing removed' => ['wireless mouse', [], '(wireless AND mouse)'];
        yield 'all removed' => ['foo bar', ['foo', 'bar'], null];
        yield 'single leaf' => ['foo', ['foo'], null];
    }

    /** @param list<string> $remove canonical forms of the leaves to remove */
    #[DataProvider('removals')]
    public function testWithout(string $query, array $remove, ?string $expected): void
    {
        $root = self::parse($query);
        $leaves = array_values(array_filter(Relaxation::positiveLeaves($root), static fn(Node $l): bool => in_array((string) $l, $remove, true)));
        self::assertCount(count($remove), $leaves);

        $reduced = Relaxation::without($root, $leaves);

        self::assertSame($expected, $reduced === null ? null : (string) $reduced);
    }

    public function testPositiveLeavesAreInOrderAndSkipNegatedWords(): void
    {
        $leaves = Relaxation::positiveLeaves(self::parse('wireless (mouse | "usb receiver") -cable name:logi*'));

        self::assertSame(['wireless', 'mouse', '"usb receiver"', 'name:logi*'], array_map(strval(...), $leaves));
    }

    public function testRemovalIsByInstanceNotByText(): void
    {
        $root = self::parse('mouse mouse pad');
        $first = Relaxation::positiveLeaves($root)[0];

        self::assertSame('(mouse AND pad)', (string) Relaxation::without($root, [$first]));
    }

    public function testWarningNamesTheIgnoredWords(): void
    {
        $leaves = Relaxation::positiveLeaves(self::parse('aluminum'));
        self::assertSame('No results for all words; ignored words that match nothing: "aluminum".', Relaxation::warning($leaves));

        $leaves = Relaxation::positiveLeaves(self::parse('alu* "usb receiver" name:foo'));
        self::assertSame('No results for all words; ignored words that match nothing: "alu*", "usb receiver", "name:foo".', Relaxation::warning($leaves));
    }
}
