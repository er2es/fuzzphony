<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
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
        // A group that would be left with only negations ("everything except ...") is not a relaxation:
        // the search does not run negation-only queries, and a relaxed one must not either.
        yield 'and left with only a negation' => ['foo -cable', ['foo'], null];
        yield 'and of several words, one left' => ['foo bar -cable', ['foo'], '(bar AND NOT cable)'];
        yield 'and of several words, none left' => ['foo bar -cable', ['foo', 'bar'], null];
        yield 'or branch left with only a negation' => ['zzqq -mouse | wireless yyqq', ['zzqq', 'yyqq'], null];
        yield 'or branch left with only a negation, the other kept' => ['(zzqq -mouse) | wireless', ['zzqq'], null];
        yield 'nested group left with only a negation' => ['wireless (foo -mouse | bar)', ['foo'], null];
        yield 'nested group keeps a word next to its negation' => ['wireless (foo bar -mouse | baz)', ['foo'], '(wireless AND ((bar AND NOT mouse) OR baz))'];
        yield 'the negation of an untouched group stays' => ['wireless (foo | bar) -cable', ['foo'], '(wireless AND bar AND NOT cable)'];
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

    public function testWarningStripsFormatCharactersButKeepsTheWordsAsTyped(): void
    {
        $leaves = [new Term("of\u{202E}fice\u{200B}"), new Term("\u{FEFF}<b>a&b</b>"), new Term('Café')];

        self::assertSame(
            'No results for all words; ignored words that match nothing: "office", "<b>a&b</b>", "Café".',
            Relaxation::warning($leaves),
            'plain text: HTML is not escaped here, the caller escapes it when rendering',
        );
    }

    public function testWarningTruncatesLongWords(): void
    {
        $long = str_repeat('é', 230);
        $exact = str_repeat('x', 40);

        self::assertSame(
            'No results for all words; ignored words that match nothing: "' . str_repeat('é', 40) . '…", "' . $exact . '".',
            Relaxation::warning([new Term($long), new Term($exact)]),
        );
        self::assertSame(
            'No results for all words; ignored words that match nothing: "name:' . str_repeat('y', 35) . '…".',
            Relaxation::warning([new FieldScoped('name', new Term(str_repeat('y', 60)))]),
            'the whole label counts, field included',
        );
    }

    public function testWarningNamesAnIdenticalWordOnce(): void
    {
        $leaves = Relaxation::positiveLeaves(self::parse('offfice mouse offfice ofice'));

        self::assertSame(
            'No results for all words; ignored words that match nothing: "offfice", "mouse", "ofice".',
            Relaxation::warning($leaves),
        );
        self::assertSame(
            'No results for all words; ignored words that match nothing: "office".',
            Relaxation::warning([new Term("of\u{200B}fice"), new Term('office')]),
            'labels that only differ by stripped characters are the same word',
        );
    }
}
