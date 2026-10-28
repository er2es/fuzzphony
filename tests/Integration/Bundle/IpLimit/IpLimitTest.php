<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\IpLimit;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `rate_limit.per: ip` end to end: a real, compiled kernel, real requests to an anonymous search endpoint, a real
 * Request::getClientIp() with and without framework.trusted_proxies, a real limiter and a real PostgreSQL search log.
 */
final class IpLimitTest extends TestCase
{
    private const array ALL_TABLES = ['iplimit_item', 'fuzzphony_items', 'fuzzphony_items__vocab', 'fuzzphony_items__next', 'fuzzphony_items__changes', 'fuzzphony_search_log'];

    private string $scratch;

    private ?IpLimitKernel $kernel = null;

    protected function setUp(): void
    {
        PostgresTestCase::dsn(); // skips without FUZZPHONY_TEST_DSN
        $this->scratch = sys_get_temp_dir() . '/fuzzphony-ip-limit-' . bin2hex(random_bytes(6));
        RecordingLogger::$lines = [];
        $this->resetTrustedProxies();
        $this->dropTables();
        $connection = PostgresTestCase::connect();
        $connection->execute('CREATE TABLE iplimit_item (id integer PRIMARY KEY, title text NOT NULL)');
        $connection->execute("INSERT INTO iplimit_item VALUES (1, 'Wireless mouse'), (2, 'Mouse pad')");
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->resetTrustedProxies();
        $this->dropTables();
        (new Filesystem())->remove($this->scratch);
    }

    private function resetTrustedProxies(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    private function dropTables(): void
    {
        PostgresTestCase::connect()->execute('DROP TABLE IF EXISTS ' . implode(', ', self::ALL_TABLES) . ' CASCADE');
    }

    /** @param array{trusted_proxies?: string, limiter?: bool, log_ip?: string|null} $options */
    private function boot(array $options = []): void
    {
        $this->kernel = new IpLimitKernel($this->scratch, $options);
        $this->kernel->boot();
        $console = new Application($this->kernel);
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:schema', '--apply' => true], ['decorated' => false]), $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:reindex'], ['decorated' => false]), $tester->getDisplay());
    }

    private function kernel(): IpLimitKernel
    {
        return $this->kernel ?? throw new \LogicException('boot() first.');
    }

    /**
     * One request to the anonymous search endpoint, then the end of the request (the search log is written there).
     *
     * @param array<string, string> $server
     */
    private function get(string $remoteAddr, array $server = [], string $path = '/search'): Response
    {
        $request = Request::create($path . '?q=mouse', 'GET', server: ['REMOTE_ADDR' => $remoteAddr] + $server);
        $response = $this->kernel()->handle($request);
        $this->kernel()->terminate($request, $response);

        return $response;
    }

    public function testTheSearchOverTheLimitIsRefusedWith429AndRetryAfterAndAnotherAddressIsNotLimited(): void
    {
        $this->boot(['limiter' => true]); // 3 searches a minute, the application's own limiter

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->get('203.0.113.45')->getStatusCode(), 'search ' . ($i + 1));
        }
        $refused = $this->get('203.0.113.45');
        self::assertSame(429, $refused->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $refused->headers->get('Retry-After'));
        self::assertLessThanOrEqual(61, (int) $refused->headers->get('Retry-After'));

        self::assertSame(200, $this->get('203.0.113.46')->getStatusCode(), 'another address has its own bucket');
        self::assertSame(429, $this->get('203.0.113.45')->getStatusCode(), 'and the first is still refused');
    }

    public function testTheBundlesDefaultLimiterAllowsSixHundredAMinute(): void
    {
        $this->boot(); // per: ip and no limiter: the entry the bundle prepends

        // the entry as compiled into the container (600 real requests would only prove what the configuration says)
        $factory = $this->kernel()->getContainer()->get('limiter.fuzzphony_ip');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        self::assertSame(600, $factory->create('203.0.113.45')->consume(0)->getLimit());
        self::assertSame(200, $this->get('203.0.113.45')->getStatusCode(), 'and the endpoint is wired to it');
    }

    public function testAnIpv6AddressIsLimitedByItsSlash64(): void
    {
        $this->boot(['limiter' => true]);

        $this->get('2001:db8:abcd:12::1');
        $this->get('2001:db8:abcd:12:aaaa:bbbb:cccc:dddd'); // rotating inside the /64
        $this->get('2001:db8:abcd:12::ffff');

        self::assertSame(429, $this->get('2001:db8:abcd:12:1:2:3:4')->getStatusCode(), 'the fourth address of one /64');
        self::assertSame(200, $this->get('2001:db8:abcd:13::1')->getStatusCode(), 'the next /64');
    }

    public function testAForwardedForHeaderIsIgnoredWithoutTrustedProxies(): void
    {
        $this->boot(['limiter' => true]);

        // an attacker names a new client in every request: it must not give him a new bucket ...
        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $forged) {
            self::assertSame(200, $this->get('203.0.113.45', ['HTTP_X_FORWARDED_FOR' => $forged])->getStatusCode());
        }
        self::assertSame(429, $this->get('203.0.113.45', ['HTTP_X_FORWARDED_FOR' => '198.51.100.4'])->getStatusCode());

        // ... nor spend the bucket of the address he names
        self::assertSame(200, $this->get('198.51.100.1')->getStatusCode(), 'the "victim" is not limited');
    }

    public function testBehindATrustedProxyTheForwardedClientIsTheKeyAndAnUntrustedPeerCannotForgeIt(): void
    {
        $this->boot(['limiter' => true, 'trusted_proxies' => '10.0.0.1']);

        // the proxy 10.0.0.1 reports two different clients: two buckets
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->get('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'])->getStatusCode());
        }
        self::assertSame(429, $this->get('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'])->getStatusCode());
        self::assertSame(200, $this->get('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.2'])->getStatusCode(), 'another client behind the same proxy');

        // a peer that is not the trusted proxy cannot name the client: its own address is the key
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->get('203.0.113.45', ['HTTP_X_FORWARDED_FOR' => '198.51.100.9' . $i])->getStatusCode());
        }
        self::assertSame(429, $this->get('203.0.113.45', ['HTTP_X_FORWARDED_FOR' => '198.51.100.99'])->getStatusCode());
    }

    public function testWithoutTrustedProxiesEveryoneBehindAProxyShareOneBucket(): void
    {
        $this->boot(['limiter' => true]); // no trusted_proxies: the application only ever sees the proxy's address

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $client) {
            self::assertSame(200, $this->get('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => $client])->getStatusCode());
        }
        self::assertSame(429, $this->get('10.0.0.1', ['HTTP_X_FORWARDED_FOR' => '198.51.100.4'])->getStatusCode(), 'a fourth client, the same bucket');
    }

    public function testASearchWithoutAnActorAndACallWithoutARequestAreNotLimited(): void
    {
        $this->boot(['limiter' => true]);

        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(200, $this->get('203.0.113.45', path: '/plain')->getStatusCode(), 'no actor: the library sees no request');
        }
        $fuzzphony = $this->kernel()->getContainer()->get(Fuzzphony::class);
        self::assertInstanceOf(Fuzzphony::class, $fuzzphony);
        for ($i = 0; $i < 8; ++$i) {
            // a plain PHP call (a command, a worker) as an anonymous actor: no request, no address, no bucket
            self::assertSame(2, $fuzzphony->in('items')->asActor(Actor::anonymous())->query('mouse')->get()->total);
        }
        self::assertSame(200, $this->get('203.0.113.45')->getStatusCode(), 'and none of that spent a token of the address');
    }

    public function testTheRefusalIsInTheLogAsRateLimitedWithoutAnAddressWhenTheLogKeepsNone(): void
    {
        $this->boot(['limiter' => true, 'log_ip' => 'off']);

        for ($i = 0; $i < 4; ++$i) {
            $this->get('203.0.113.45');
        }

        $rows = PostgresTestCase::connect()->fetchAll('SELECT status, query FROM fuzzphony_search_log ORDER BY id');
        self::assertSame(['ok', 'ok', 'ok', 'rate_limited'], array_column($rows, 'status'));
        $all = PostgresTestCase::connect()->fetchAll('SELECT row_to_json(l)::text AS j FROM fuzzphony_search_log l');
        self::assertCount(4, $all);
        foreach ($all as $row) {
            self::assertIsString($row['j']);
            self::assertStringNotContainsString('203.0.113', $row['j'], 'the address is not stored when search_log.ip is off');
        }
        $ips = PostgresTestCase::connect()->fetchAll('SELECT actor_ip FROM fuzzphony_search_log');
        self::assertSame([null, null, null, null], array_column($ips, 'actor_ip'), 'the actor_ip column holds nothing');
    }

    public function testTheAddressIsStoredOnlyAsTheLogSettingSays(): void
    {
        $this->boot(['limiter' => true, 'log_ip' => 'masked']);

        for ($i = 0; $i < 4; ++$i) {
            $this->get('203.0.113.45');
        }

        $rows = PostgresTestCase::connect()->fetchAll('SELECT status, actor_ip FROM fuzzphony_search_log ORDER BY id');
        self::assertSame(['ok', 'ok', 'ok', 'rate_limited'], array_column($rows, 'status'));
        self::assertSame(array_fill(0, 4, '203.0.113.0'), array_column($rows, 'actor_ip'));
    }

    public function testTheRawAddressIsNowhereInTheLogsTheMetricsOrTheErrorPage(): void
    {
        $this->boot(['limiter' => true]);

        $refused = null;
        for ($i = 0; $i < 4; ++$i) {
            $refused = $this->get('203.0.113.45');
        }

        self::assertNotNull($refused);
        self::assertSame(429, $refused->getStatusCode());
        self::assertStringNotContainsString('203.0.113.45', (string) $refused->getContent());
        self::assertStringNotContainsString('203.0.113.45', (string) $refused->headers);
        self::assertNotSame([], RecordingLogger::$lines, 'the refusal is logged (a warning) and counted');
        self::assertNotEmpty(array_filter(RecordingLogger::$lines, static fn(string $l): bool => str_contains($l, 'rate_limited') || str_contains($l, 'refused a search')));
        foreach (RecordingLogger::$lines as $line) {
            self::assertStringNotContainsString('203.0.113', $line);
        }
    }
}
