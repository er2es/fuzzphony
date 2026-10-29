<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuzzphonySuggestTest extends TestCase
{
    /** @var list<array{string, int}> prefix and limit the engine was asked for */
    private array $asked = [];

    private function fuzzphony(bool $withVocabulary = true): Fuzzphony
    {
        if ($withVocabulary) {
            $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
            assert($engine instanceof Engine);
            $engine->method('suggestWords')->willReturnCallback(function (IndexDefinition $index, string $prefix, int $limit): array {
                $this->asked[] = [$prefix, $limit];

                return [$prefix . 'one', $prefix . 'two'];
            });
        } else {
            $engine = self::createStub(Engine::class);
        }

        return new Fuzzphony($engine, new IndexRegistry([Indexes::products()]));
    }

    public function testEightCompletionsAreAskedForByDefault(): void
    {
        self::assertSame(['wireless heaone', 'wireless heatwo'], $this->fuzzphony()->suggest('products', 'wireless hea'));
        self::assertSame([['hea', 8]], $this->asked);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function limits(): iterable
    {
        yield '1' => [1, true];
        yield '20' => [20, true];
        yield '0' => [0, false];
        yield '21' => [21, false];
    }

    #[DataProvider('limits')]
    public function testTheLimitIsOneToTwenty(int $limit, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(InvalidArgument::class);
            $this->expectExceptionMessage(sprintf('The suggestion limit must be between 1 and 20, got %d.', $limit));
        }

        $this->fuzzphony()->suggest('products', 'hea', $limit);

        self::assertSame([['hea', $limit]], $this->asked);
    }

    public function testLeadingWhitespaceIsDroppedAndTheTextIsCutWhereASearchIsCut(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertSame(['heaone'], array_slice($fuzzphony->suggest('products', "  \t hea"), 0, 1));

        $fuzzphony->suggest('products', str_repeat('é', 300));
        self::assertSame([str_repeat('é', 256), 8], $this->asked[1], 'max_query_length (256 by default)');

        $fuzzphony->suggest('products', str_repeat('x', 256) . 'yz');
        self::assertSame([str_repeat('x', 256), 8], $this->asked[2]);
    }

    public function testTheCutFollowsTheIndexsMaxQueryLength(): void
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        assert($engine instanceof Engine);
        $asked = [];
        $engine->method('suggestWords')->willReturnCallback(static function (IndexDefinition $index, string $prefix, int $limit) use (&$asked): array {
            $asked[] = $prefix;

            return [];
        });
        $index = Indexes::products()->withThresholds(new Thresholds(maxQueryLength: 20));

        (new Fuzzphony($engine, new IndexRegistry([$index])))->suggest('products', 'wireless ' . str_repeat('m', 30));

        self::assertSame(['m' . str_repeat('m', 10)], $asked, 'the text is cut at 20 characters: "wireless " + 11 letters');
    }

    public function testTheTextBeforeTheLastWordSpansLines(): void
    {
        self::assertSame(["wireless\nheaone", "wireless\nheatwo"], $this->fuzzphony()->suggest('products', "wireless\nhea"));
    }

    public function testNothingIsSuggestedAfterAFinishedWordOrWithoutAVocabulary(): void
    {
        self::assertSame([], $this->fuzzphony()->suggest('products', 'wireless '));
        self::assertSame([], $this->fuzzphony(false)->suggest('products', 'hea'));
        self::assertSame([], $this->asked);
    }
}
