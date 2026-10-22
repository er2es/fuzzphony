<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\FieldScoped;
use Fuzzphony\Core\Query\Ast\NodeInspector;
use Fuzzphony\Core\Query\Ast\Not;
use Fuzzphony\Core\Query\Ast\Phrase;
use Fuzzphony\Core\Query\Ast\Term;
use PHPUnit\Framework\TestCase;

final class NodeInspectorMutationsTest extends TestCase
{
    public function testNothingIsNotPositive(): void
    {
        self::assertFalse(NodeInspector::hasPositive(null));
    }

    public function testAWordAPhraseAndAScopedWordArePositive(): void
    {
        self::assertTrue(NodeInspector::hasPositive(new Term('a')));
        self::assertTrue(NodeInspector::hasPositive(new Phrase(['a', 'b'])));
        self::assertTrue(NodeInspector::hasPositive(new FieldScoped('name', new Term('a'))));
    }

    public function testAnExclusionAloneIsNotPositiveButBesideAWordItIs(): void
    {
        self::assertFalse(NodeInspector::hasPositive(new Not(new Term('a'))));
        self::assertTrue(NodeInspector::hasPositive(new AllOf([new Not(new Term('a')), new Term('b')])));
        self::assertFalse(NodeInspector::hasPositive(new AllOf([new Not(new Term('a')), new Not(new Term('b'))])));
    }

    public function testAnEitherNeedsEveryBranchToBePositive(): void
    {
        self::assertTrue(NodeInspector::hasPositive(new AnyOf([new Term('a'), new Term('b')])));
        self::assertFalse(NodeInspector::hasPositive(new AnyOf([new Term('a'), new Not(new Term('b'))])));
    }

    public function testTheQueryWithoutItsExclusions(): void
    {
        $a = new Term('a');
        $b = new Term('b');
        $either = new AnyOf([$a, $b], true);
        self::assertSame($a, NodeInspector::withoutExclusions($a));
        self::assertNull(NodeInspector::withoutExclusions(new Not($a)));
        self::assertNull(NodeInspector::withoutExclusions(new AllOf([new Not($a), new Not($b)])));
        $untouched = new AllOf([$a, $either]);
        self::assertSame($untouched, NodeInspector::withoutExclusions($untouched), 'nothing to remove: the same instance');
        self::assertSame($a, NodeInspector::withoutExclusions(new AllOf([$a, new Not($b)])), 'one word is left: it is the word');
        self::assertEquals(new AllOf([$a, $b]), NodeInspector::withoutExclusions(new AllOf([$a, new Not(new Term('c')), $b])));
        self::assertEquals(new AllOf([$a, $either]), NodeInspector::withoutExclusions(new AllOf([$a, new Not(new Term('c')), $either])));
        self::assertEquals(new AnyOf([$a, $b], true), NodeInspector::withoutExclusions(new AnyOf([$a, new Not(new Term('c')), $b], true)), 'an expansion stays one');
        self::assertEquals(new AllOf([$a, new AnyOf([$b, new Term('d')])]), NodeInspector::withoutExclusions(new AllOf([$a, new AnyOf([$b, new Not(new Term('c')), new Term('d')]), new Not(new Term('e'))])), 'nested');
    }
}
