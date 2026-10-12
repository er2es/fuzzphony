<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\TestCase;

final class SynonymsParsingTest extends TestCase
{
    public function testIndentedCommentsAndBlankLinesAreSkipped(): void
    {
        $synonyms = Synonyms::fromText(implode("\n", ['   # indented comment', '    ', "\t", 'tv, television', '  ']));

        self::assertSame([['tv', 'television']], $synonyms->groups);
        self::assertSame([], $synonyms->rules);
    }

    public function testEmptyListItemsAreDropped(): void
    {
        $synonyms = Synonyms::fromText(implode("\n", ['a, , b,, c', 'src => x, , y,']));

        self::assertSame([['a', 'b', 'c']], $synonyms->groups);
        self::assertSame([['source' => 'src', 'targets' => ['x', 'y']]], $synonyms->rules);
    }

    public function testWindowsLineEndingsAreRead(): void
    {
        $synonyms = Synonyms::fromText("tv, television\r\nlaptop => notebook\r\n");

        self::assertSame([['tv', 'television']], $synonyms->groups);
        self::assertSame([['source' => 'laptop', 'targets' => ['notebook']]], $synonyms->rules);
    }

    public function testTheTextFormatIsWrittenOneEntryPerLine(): void
    {
        $synonyms = Synonyms::fromEntries([['tv', 'television'], ['ssd', 'solid state drive'], 'laptop => notebook | portable']);

        self::assertSame("tv, television\nssd, solid state drive\nlaptop => notebook, portable\n", $synonyms->toText());
        self::assertSame('', (new Synonyms())->toText());
    }

    public function testADirectoryIsNotAReadableSynonymFile(): void
    {
        try {
            Synonyms::fromFile(sys_get_temp_dir(), 'products');
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertSame('products', $e->index);
            self::assertSame([sprintf('Synonym file "%s" does not exist or cannot be read.', sys_get_temp_dir())], $e->violations);
        }
    }

    public function testTheSourceOfARuleMustBePlainWordsToo(): void
    {
        $violations = Synonyms::fromEntries(['tv* => television', 'a OR b => c'])->violations();

        self::assertSame([
            'The synonym rule for "tv*" has a word that is not plain words (no quotes, operators, "*" or ":", at most 16 words): "tv*".',
            'The synonym rule for "a OR b" has a word that is not plain words (no quotes, operators, "*" or ":", at most 16 words): "a OR b".',
        ], $violations);
    }
}
