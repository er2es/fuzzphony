<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Typeahead;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeaheadTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function texts(): iterable
    {
        yield 'one word' => ['cr', '(cr | cr*)'];
        yield 'the last word only' => ['wireless hea', 'wireless (hea | hea*)'];
        yield 'accented, unicode' => ['Crè', '(Crè | Crè*)'];
        yield 'a digit' => ['usb 3', 'usb (3 | 3*)'];
        yield 'a field is only a prefix' => ['brand:son', 'brand:son*'];
        yield 'an exclusion is only a prefix' => ['mouse -ca', 'mouse -ca*'];
        yield 'an exclusion with !' => ['!ca', '!ca*'];
        yield 'after a group' => ['(mouse OR pad) hea', '(mouse OR pad) (hea | hea*)'];
        yield 'finished with a space' => ['wireless ', 'wireless '];
        yield 'finished with a symbol' => ['wireless*', 'wireless*'];
        yield 'nothing' => ['', ''];
        yield 'inside an open quote' => ['"noise canc', '"noise canc'];
        yield 'after a closed quote' => ['"noise cancelling" hea', '"noise cancelling" (hea | hea*)'];
        yield 'an operator' => ['mouse OR', 'mouse OR'];
        yield 'a hyphenated word is left alone' => ['wi-fi', 'wi-fi'];
    }

    #[DataProvider('texts')]
    public function testTheLastWordBecomesAPrefixToo(string $text, string $expected): void
    {
        self::assertSame($expected, Typeahead::lastWordAsPrefix($text));
    }

    public function testASuggestionLosesThePrefixAlternative(): void
    {
        self::assertSame('mouse', Typeahead::withoutPrefixAlternative('mouse | mouse*'));
        self::assertSame('wireless mouse', Typeahead::withoutPrefixAlternative('wireless (mouse | mouse*)'));
        self::assertSame('wireless mouse -cable', Typeahead::withoutPrefixAlternative('wireless (mouse | mouse*) -cable'));
        self::assertSame('"a b" c | d*', Typeahead::withoutPrefixAlternative('"a b" c | d*'), 'anything else stays');
    }
}
