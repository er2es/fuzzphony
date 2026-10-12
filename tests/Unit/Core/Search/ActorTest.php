<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Search\Actor;
use PHPUnit\Framework\TestCase;

final class ActorTest extends TestCase
{
    public function testAnActorCarriesATenantAndAUser(): void
    {
        $actor = new Actor(tenant: 7, user: 'ada');

        self::assertSame(7, $actor->tenant);
        self::assertSame('ada', $actor->user);
        self::assertFalse($actor->isAnonymous());
    }

    public function testTheAnonymousActorHasNeither(): void
    {
        $actor = Actor::anonymous();

        self::assertNull($actor->tenant);
        self::assertNull($actor->user);
        self::assertTrue($actor->isAnonymous());
        self::assertFalse((new Actor(user: 'ada'))->isAnonymous());
        self::assertFalse((new Actor(tenant: 'acme'))->isAnonymous());
    }

    public function testActorRequiredIsAnInvalidQueryNamingTheIndex(): void
    {
        $e = ActorRequired::forIndex('products');

        self::assertInstanceOf(InvalidQuery::class, $e);
        self::assertInstanceOf(FuzzphonyException::class, $e);
        self::assertStringContainsString('"products"', $e->getMessage());
        self::assertStringContainsString('tenant', $e->getMessage());
    }
}
