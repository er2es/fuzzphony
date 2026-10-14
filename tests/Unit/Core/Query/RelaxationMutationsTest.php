<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\Relaxation;
use PHPUnit\Framework\TestCase;

final class RelaxationMutationsTest extends TestCase
{
    public function testTheLengthLimitCountsCharactersNotBytes(): void
    {
        // 30 characters, 60 bytes: under the limit of 40 characters, so it is named whole
        $word = str_repeat('é', 30);

        self::assertSame(
            'No results for all words; ignored words that match nothing: "' . $word . '".',
            Relaxation::warning([new Term($word)]),
        );
    }
}
