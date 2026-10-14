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
}
