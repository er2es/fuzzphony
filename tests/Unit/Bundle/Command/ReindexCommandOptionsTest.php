<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Vocabulary;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:reindex against an engine that records what it is asked (no database): the batch size, the headings, several indexes. */
final class ReindexCommandOptionsTest extends TestCase
{
    /** @var list<int> the limit of every sourceIds() call */
    private array $limits = [];

    /** @return iterable<string, array{array<string, string>, int}> */
    public static function batchSizes(): iterable
    {
        yield 'the command default' => [[], 5000];
        yield 'zero is raised to one' => [['--batch' => '0'], 1];
        yield 'negative is raised to one' => [['--batch' => '-4'], 1];
        yield 'one' => [['--batch' => '1'], 1];
        yield 'two' => [['--batch' => '2'], 2];
        yield 'a large batch is kept' => [['--batch' => '20000'], 20000];
    }

    /** @param array<string, string> $options */
    #[DataProvider('batchSizes')]
    public function testTheBatchSize(array $options, int $expected): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('sourceIds')->willReturnCallback(function (IndexDefinition $index, int|string|null $after, int $limit): array {
            $this->limits[] = $limit;

            return [];
        });

        $status = $this->tester($engine, [Indexes::products('manual')])->execute($options + ['--in-place' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([$expected], $this->limits);
    }

    public function testEveryIndexGetsItsHeadingAndTheRunEndsWithATip(): void
    {
        $tester = $this->tester(self::createStub(Engine::class), [Indexes::products('manual'), $this->index('articles')]);

        $status = $tester->execute(['--in-place' => true], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString("Reindexing \"products\"\n", $display);
        self::assertStringContainsString("Reindexing \"articles\"\n", $display);
        self::assertStringContainsString('Done. Tip: run fuzzphony:doctor to verify coverage.', $display);
    }

    public function testTheVocabularyOfEveryIndexIsRebuiltAndOneWithoutFuzzyFieldsDoesNotStopTheRest(): void
    {
        $rebuilt = [];
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Vocabulary::class]);
        $engine->method('rebuildVocabulary')->willReturnCallback(static function (IndexDefinition $index) use (&$rebuilt): int {
            $rebuilt[] = $index->name;

            return 1234;
        });
        assert($engine instanceof Engine);
        $tester = $this->tester($engine, [$this->index('plain', fuzzy: false), Indexes::products('manual'), $this->index('articles')]);

        $status = $tester->execute(['--vocabulary' => true], ['interactive' => false]);

        $display = $tester->getDisplay(true);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame(['products', 'articles'], $rebuilt, 'the index without a fuzzy field is skipped, the others are not');
        self::assertStringContainsString("Reindexing \"plain\"\n", $display);
        self::assertStringContainsString('No fuzzy field, so no vocabulary.', $display);
        self::assertSame(2, preg_match_all('/^  1,234 words in the vocabulary, in \d+\.\ds$/m', $display));
        self::assertStringContainsString('Done. Tip', $display);
    }

    /** @param list<IndexDefinition> $indexes */
    private function tester(Engine $engine, array $indexes): CommandTester
    {
        return new CommandTester(new ReindexCommand(new Fuzzphony($engine, new IndexRegistry($indexes))));
    }

    private function index(string $name, bool $fuzzy = true): IndexDefinition
    {
        return IndexDefinition::builder($name)->fromQuery('SELECT id, title FROM fz_' . $name)->sync('manual')->field('title', 'A', fuzzy: $fuzzy)->build();
    }
}
