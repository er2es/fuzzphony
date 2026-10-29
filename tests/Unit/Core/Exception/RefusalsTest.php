<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Exception\RateLimited;
use Fuzzphony\Core\Exception\RebuildAlreadyRunning;
use Fuzzphony\Core\Exception\SearchRefused;
use PHPUnit\Framework\TestCase;

final class RefusalsTest extends TestCase
{
    public function testTheThreeRefusalsShareOneTypeAndKeepTheirParents(): void
    {
        $denied = AccessDenied::forIndex('products');
        $actor = ActorRequired::forIndex('products');
        $limited = new RateLimited(5);

        foreach ([$denied, $actor, $limited] as $refusal) {
            self::assertInstanceOf(SearchRefused::class, $refusal);
        }
        self::assertInstanceOf(\RuntimeException::class, $denied);
        self::assertInstanceOf(\RuntimeException::class, $limited);
        self::assertInstanceOf(InvalidQuery::class, $actor, 'still caught by catch (InvalidQuery)');
        self::assertNotInstanceOf(SearchRefused::class, InvalidQuery::missingTenant('products'), 'other query mistakes are not refusals');
    }

    public function testARebuildThatAnotherRunHoldsIsAStateNotAWrongArgument(): void
    {
        $e = new RebuildAlreadyRunning('busy');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }
}
