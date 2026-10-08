<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Query\QueryRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryRendererTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function queries(): iterable
    {
        yield 'a word' => ['mouse'];
        yield 'two words' => ['wireless mouse'];
        yield 'an or' => ['mouse | trackpad'];
        yield 'an exclusion' => ['mouse -cable'];
        yield 'a prefix' => ['keyb*'];
        yield 'a phrase' => ['"wireless mouse"'];
        yield 'a field' => ['brand:logitech'];
        yield 'a scoped phrase' => ['name:"mx master"'];
        yield 'a group' => ['(mouse | trackpad) -cable'];
        yield 'an excluded group' => ['mouse -(cable | wired)'];
        yield 'groups in groups' => ['(a b) | (c d)'];
        yield 'an or inside an and inside an or' => ['a | (b (c | d))'];
        yield 'a hyphenated word' => ['wi-fi router'];
        yield 'an excluded phrase' => ['mouse -"gaming mouse"'];
    }

    #[DataProvider('queries')]
    public function testTheTextParsesBackToTheSameQuery(string $text): void
    {
        $parser = new QueryParser();
        $root = $parser->parse($text)->root;
        self::assertNotNull($root);

        $rendered = QueryRenderer::render($root);

        self::assertEquals($root, $parser->parse($rendered)->root, $rendered);
    }

    public function testWholeWordsAreReplacedWhateverTheirCaseAndPlace(): void
    {
        $replace = ['hedphones' => 'headphones', 'mose' => 'mouse', 'wireles' => 'wireless'];
        $parser = new QueryParser();
        $render = static function (string $text) use ($parser, $replace): string {
            $root = $parser->parse($text)->root;
            self::assertNotNull($root);

            return QueryRenderer::render($root, $replace);
        };

        self::assertSame('headphones', $render('Hedphones'));
        self::assertSame('wireless mouse -cable', $render('wireles mose -cable'));
        self::assertSame('"wireless mouse"', $render('"wireles mose"'));
        self::assertSame('name:headphones brand:sony', $render('name:hedphones brand:sony'));
        self::assertSame('keyb* | mouse', $render('keyb* | mose'));
        self::assertSame('mouse -(headphones | wired)', $render('mose -(hedphones | wired)'));
        self::assertSame('moses', $render('moses'), 'a word is never replaced in part');
        self::assertSame('wireless*', $render('wireles*'), 'the prefix mark stays');
    }
}
