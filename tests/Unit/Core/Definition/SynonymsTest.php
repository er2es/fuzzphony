<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Exception\InvalidDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynonymsTest extends TestCase
{
    public function testGroupsAndOneWayRulesRoundTripThroughEntries(): void
    {
        $entries = [['tv', 'television'], ['ssd', 'solid state drive'], 'laptop => notebook | portable computer'];

        $synonyms = Synonyms::fromEntries($entries);

        self::assertSame([['tv', 'television'], ['ssd', 'solid state drive']], $synonyms->groups);
        self::assertSame([['source' => 'laptop', 'targets' => ['notebook', 'portable computer']]], $synonyms->rules);
        self::assertSame($entries, $synonyms->toEntries());
        self::assertFalse($synonyms->isEmpty());
        self::assertEquals($synonyms, Synonyms::fromEntries($synonyms->toEntries()));
    }

    public function testWhitespaceIsTrimmedAndNothingIsEmpty(): void
    {
        $synonyms = Synonyms::fromEntries([[' tv ', 'television '], '  a  =>  b|c  ']);

        self::assertSame([['tv', 'television']], $synonyms->groups);
        self::assertSame([['source' => 'a', 'targets' => ['b', 'c']]], $synonyms->rules);
        self::assertTrue((new Synonyms())->isEmpty());
        self::assertTrue(Synonyms::fromEntries([])->isEmpty());
    }

    public function testAnEntryOfTheWrongShapeIsReportedWithItsPosition(): void
    {
        try {
            Synonyms::fromEntries([['tv', 'television'], 'just a string', ['a', 3], 7]);
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertCount(3, $e->violations);
            self::assertStringContainsString('Synonym entry 2 must be a list of words', $e->violations[0]);
            self::assertSame('synonyms', $e->index);
            self::assertStringContainsString('Synonym entry 3', $e->violations[1]);
            self::assertStringContainsString('Synonym entry 4', $e->violations[2]);
        }
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'a group of one' => [[['tv']], 'Synonym group 1 (tv) needs at least two different members.'];
        yield 'a group of the same word' => [[['TV', 'tv']], 'needs at least two different members'];
        yield 'a member without a letter or digit' => [[['tv', '+++']], 'has a member without a letter or digit: "+++"'];
        yield 'a member in two groups' => [[['tv', 'television'], ['telly', 'TV']], '"TV" is in more than one synonym group'];
        yield 'a rule without a target' => [['laptop =>'], 'The synonym rule for "laptop" needs at least one target'];
        yield 'a rule with a target without a letter' => [['laptop => ---'], 'The synonym rule for "laptop" needs at least one target'];
        yield 'a rule without a source' => [['=> notebook'], 'needs a word before "=>"'];
        yield 'a member with query syntax' => [[['-tv', 'television']], 'has a member that is not plain words'];
        yield 'a member with an operator word' => [[['tv OR telly', 'television']], 'has a member that is not plain words'];
        yield 'a quoted member' => [[['"solid state"', 'ssd']], 'has a member that is not plain words'];
        yield 'a prefix member' => [[['tv*', 'television']], 'has a member that is not plain words'];
        yield 'a field-scoped member' => [[['name:tv', 'television']], 'has a member that is not plain words'];
        yield 'a member of more than sixteen words' => [[[implode(' ', array_fill(0, 17, 'word')), 'long']], 'has a member that is not plain words'];
        yield 'a group that is too big' => [[array_map(static fn(int $i): string => 'word' . $i, range(1, 33))], 'has more than 32 members'];
        yield 'a rule with query syntax' => [['tv => "television"'], 'has a word that is not plain words'];
        yield 'a rule with too many targets' => [['a => ' . implode(' | ', array_map(static fn(int $i): string => 'word' . $i, range(1, 33)))], 'has more than 32 targets'];
        yield 'a rule source twice' => [['a => b', 'A => c'], 'Synonym rule for "A" is defined twice'];
    }

    /** @param list<mixed> $entries */
    #[DataProvider('invalid')]
    public function testViolations(array $entries, string $expected): void
    {
        $violations = Synonyms::fromEntries($entries)->violations();

        self::assertNotSame([], $violations);
        self::assertStringContainsString($expected, implode("\n", $violations));
    }

    public function testValidSynonymsHaveNoViolationsAndAWordCanBeInAGroupAndARule(): void
    {
        self::assertSame([], Synonyms::fromEntries([['tv', 'television'], ['ssd', 'solid state drive'], 'tv => telly'])->violations());
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        self::assertCount(3, Synonyms::fromEntries([['x'], ['a', '+'], 'b =>'])->violations());
    }

    public function testARuleDropsEmptyTargets(): void
    {
        self::assertSame([['source' => 'a', 'targets' => ['b', 'c']]], Synonyms::fromEntries(['a => | b | | c |'])->rules, 'a list, without the empty targets');
    }

    public function testOnlyGroupsOrOnlyRulesIsNotEmpty(): void
    {
        self::assertFalse(Synonyms::fromEntries([['a', 'b']])->isEmpty());
        self::assertFalse(Synonyms::fromEntries(['a => b'])->isEmpty());
    }

    public function testASecondArrowIsAMistakeAndTheIndexNameIsInTheError(): void
    {
        try {
            Synonyms::fromEntries(['a => b => c'], 'products');
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertSame('products', $e->index);
            self::assertStringContainsString('one "=>"', $e->violations[0]);
        }
    }

    public function testHyphensAndApostrophesAreFineInAMember(): void
    {
        self::assertSame([], Synonyms::fromEntries([['wi-fi', 'wireless'], ["o'brien", 'obrien'], ['c++', 'cpp']])->violations());
    }

    public function testTheLargestAllowedGroupAndRuleAreValid(): void
    {
        $members = array_map(static fn(int $i): string => 'word' . $i, range(1, 32));

        self::assertSame([], Synonyms::fromEntries([$members, 'source => ' . implode(' | ', $members)])->violations());
    }

    public function testAnEntryUnderAStringKeyIsNamedByItsKey(): void
    {
        try {
            Synonyms::fromEntries(['tv' => 'television']);
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('Synonym entry "tv" must be a list of words', $e->violations[0]);
        }
    }

    public function testTheMessagesNameWhatIsWrong(): void
    {
        self::assertSame(['Synonym rule for "A" is defined twice; list its targets together: "a => b | c".'], Synonyms::fromEntries(['a => b', 'A => c'])->violations());
        self::assertSame(['Synonym group 1 (tv, +++) has a member without a letter or digit: "+++".'], Synonyms::fromEntries([['tv', '+++']])->violations());
        self::assertSame(['Synonym group 1 (tv, tv) needs at least two different members.'], Synonyms::fromEntries([['tv', 'tv']])->violations());
    }

    public function testTheSolrFormatIsReadAndWrittenBack(): void
    {
        $text = "# comment
tv, television , telly

laptop => notebook, portable
a, b => c
";

        $synonyms = Synonyms::fromText($text);

        self::assertSame([['tv', 'television', 'telly']], $synonyms->groups);
        self::assertSame([['source' => 'laptop', 'targets' => ['notebook', 'portable']], ['source' => 'a', 'targets' => ['c']], ['source' => 'b', 'targets' => ['c']]], $synonyms->rules);
        self::assertEquals($synonyms, Synonyms::fromText($synonyms->toText()));
        self::assertSame('', (new Synonyms())->toText());
    }

    public function testAFileIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'syn');
        self::assertNotFalse($path);
        file_put_contents($path, "tv, television
");

        try {
            self::assertSame([['tv', 'television']], Synonyms::fromFile($path)->groups);
        } finally {
            unlink($path);
        }
    }

    public function testAMissingFileAndAnArrowTwiceAreReported(): void
    {
        try {
            Synonyms::fromFile('/no/such/file.txt', 'products');
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertStringContainsString('does not exist or cannot be read', $e->violations[0]);
        }
        try {
            Synonyms::fromText("tv
a => b => c");
            self::fail('Expected InvalidDefinition.');
        } catch (InvalidDefinition $e) {
            self::assertSame(['Line 2 has more than one "=>".'], $e->violations);
        }
    }
}
