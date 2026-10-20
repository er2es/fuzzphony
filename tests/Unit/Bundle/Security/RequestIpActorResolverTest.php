<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\RequestIpActorResolver;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The bundle gives the actor the client address Symfony computes (trusted proxies included), and never reads a header itself. */
final class RequestIpActorResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    private function resolver(?Request $request, Actor $actor): RequestIpActorResolver
    {
        $inner = new class ($actor) implements ActorResolver {
            public function __construct(private readonly Actor $actor) {}

            public function resolve(): Actor
            {
                return $this->actor;
            }
        };
        $stack = new RequestStack();
        if ($request !== null) {
            $stack->push($request);
        }

        return new RequestIpActorResolver($inner, $stack);
    }

    public function testTheActorGetsTheClientAddressAndKeepsTenantAndUser(): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.45']);

        $actor = $this->resolver($request, new Actor('acme', 'ada'))->resolve();

        self::assertSame(['acme', 'ada', '203.0.113.45'], [$actor->tenant, $actor->user, $actor->ip]);
    }

    public function testAnAnonymousVisitorGetsTheAddressAndStaysAnonymous(): void
    {
        $actor = $this->resolver(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.45']), Actor::anonymous())->resolve();

        self::assertNull($actor->user);
        self::assertSame('203.0.113.45', $actor->ip);
    }

    public function testAnActorThatCarriesAnAddressKeepsIt(): void
    {
        $own = new Actor('acme', 'ada', '198.51.100.7');

        self::assertSame($own, $this->resolver(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.45']), $own)->resolve());
    }

    public function testWithoutARequestTheActorIsReturnedAsIs(): void
    {
        $actor = new Actor('acme', 'ada');

        self::assertSame($actor, $this->resolver(null, $actor)->resolve());
    }

    public function testAForwardedHeaderFromAnUntrustedClientIsIgnored(): void
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.45', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']);

        self::assertSame('203.0.113.45', $this->resolver($request, Actor::anonymous())->resolve()->ip);
    }

    public function testAForwardedHeaderIsHonouredOnlyBehindATrustedProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $behindProxy = Request::create('/', server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.45']);

        self::assertSame('203.0.113.45', $this->resolver($behindProxy, Actor::anonymous())->resolve()->ip);

        $spoofed = Request::create('/', server: ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']);

        self::assertSame('198.51.100.9', $this->resolver($spoofed, Actor::anonymous())->resolve()->ip, 'a proxy that is not trusted cannot name the client');
    }

    public function testASubRequestGivesTheAddressOfTheMainRequest(): void
    {
        $inner = new class implements ActorResolver {
            public function resolve(): Actor
            {
                return Actor::anonymous();
            }
        };
        $stack = new RequestStack();
        $stack->push(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.45']));
        $stack->push(Request::create('/fragment', server: ['REMOTE_ADDR' => '127.0.0.1']));

        self::assertSame('203.0.113.45', (new RequestIpActorResolver($inner, $stack))->resolve()->ip);
    }
}
