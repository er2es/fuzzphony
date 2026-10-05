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
            self::assertStringContainsString('Entry 1 must be a list of words', $e->violations[0]);
            self::assertStringContainsString('Entry 2', $e->violations[1]);
            self::assertStringContainsString('Entry 3', $e->violations[2]);
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

    public function testARuleKeepsEverythingAfterTheFirstArrowAsItsTargetsAndDropsEmptyOnes(): void
    {
        self::assertSame([['source' => 'a', 'targets' => ['b => c']]], Synonyms::fromEntries(['a => b => c'])->rules);
        self::assertSame([['source' => 'a', 'targets' => ['b', 'c']]], Synonyms::fromEntries(['a => | b | | c |'])->rules, 'a list, without the empty targets');
    }

    public function testOnlyGroupsOrOnlyRulesIsNotEmpty(): void
    {
        self::assertFalse(Synonyms::fromEntries([['a', 'b']])->isEmpty());
        self::assertFalse(Synonyms::fromEntries(['a => b'])->isEmpty());
    }
}
