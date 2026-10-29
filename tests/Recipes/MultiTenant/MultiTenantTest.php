<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Fuzzphony\Tests\Recipes\RecipeOutput;
use Fuzzphony\Tests\Support\RestoresPhpHandlers;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Everything docs/recipes/multi-tenant-saas.md claims, run against PostgreSQL through a real kernel and real requests. */
final class MultiTenantTest extends KernelTestCase
{
    use RestoresPhpHandlers;

    protected static function getKernelClass(): string
    {
        return MultiTenantKernel::class;
    }

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        $connection->execute('DROP TABLE IF EXISTS recipe_ticket, fuzzphony_tickets, fuzzphony_tickets__vocab, fuzzphony_tickets__next, fuzzphony_tickets__changes, fuzzphony_search_log CASCADE');
        $connection->execute(<<<'SQL'
            CREATE TABLE recipe_ticket (
                id integer PRIMARY KEY, account_id integer NOT NULL, subject text NOT NULL, body text NOT NULL, internal_note text NOT NULL, status text NOT NULL
            )
            SQL);
        foreach ([
            [1, 1, 'Invoice missing', 'Our March invoice never arrived', 'customer is on the refund watchlist', 'open'],
            [2, 1, 'Cannot reset password', 'The reset mail does not arrive', 'escalated to security', 'open'],
            [3, 1, 'Invoice total is wrong', 'The VAT line is wrong', 'billing bug 4711', 'closed'],
            [4, 2, 'Invoice question', 'Where do I find my invoice', 'vip customer', 'open'],
            [5, 2, 'Export to CSV fails', 'The export stops after 100 rows', 'known issue', 'open'],
        ] as [$id, $account, $subject, $body, $note, $status]) {
            $connection->execute('INSERT INTO recipe_ticket VALUES (:id, :a, :s, :b, :n, :st)', ['id' => $id, 'a' => $account, 's' => $subject, 'b' => $body, 'n' => $note, 'st' => $status]);
        }

        self::bootKernel();
        $limiterPool = self::getContainer()->get('recipe.limiter_pool');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiterPool);
        $limiterPool->clear(); // a filesystem pool keeps its state from the previous run
        $tester = $this->console();
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:schema', '--apply' => true], ['decorated' => false]), $tester->getDisplay());
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:reindex'], ['decorated' => false]), $tester->getDisplay());
    }

    protected function tearDown(): void
    {
        // the tables of this recipe must not outlive it: a trigger on recipe_ticket would be counted by other suites
        PostgresTestCase::connect()->execute('DROP TABLE IF EXISTS recipe_ticket, fuzzphony_tickets, fuzzphony_tickets__vocab, fuzzphony_tickets__next, fuzzphony_tickets__changes, fuzzphony_search_log CASCADE');
        parent::tearDown();
    }

    private function kernel(): MultiTenantKernel
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(MultiTenantKernel::class, $kernel);

        return $kernel;
    }

    private function console(): ApplicationTester
    {
        $console = new Application(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $console->setAutoExit(false);

        return new ApplicationTester($console);
    }

    private static function ada(): AppUser
    {
        return new AppUser(new Account(1), 'ada');
    }

    /**
     * One request to the recipe's controller as $user (null: nobody is logged in), then the end of the request, when the
     * bundle writes the search log.
     *
     * @return array{int, list<int>, Response}
     */
    private function get(?AppUser $user, string $q, string $ip = '203.0.113.45', string $extra = ''): array
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken($user === null ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $kernel = $this->kernel();
        $request = Request::create('/tickets/search?' . $extra, 'GET', ['q' => $q], server: ['REMOTE_ADDR' => $ip]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $tokens->setToken(null);

        $json = json_decode((string) $response->getContent(), true);
        $ids = [];
        if (is_array($json) && is_array($json['hits'] ?? null)) {
            foreach ($json['hits'] as $hit) {
                self::assertIsArray($hit);
                self::assertIsInt($hit['id']);
                $ids[] = $hit['id'];
            }
        }

        return [$response->getStatusCode(), $ids, $response];
    }

    /** @return list<array<string, mixed>> */
    private function logRows(): array
    {
        return PostgresTestCase::connect()->fetchAll('SELECT tenant, actor_user, actor_ip, query, hits, status FROM fuzzphony_search_log ORDER BY id');
    }

    private function fuzzphony(): Fuzzphony
    {
        $fuzzphony = self::getContainer()->get(Fuzzphony::class);
        self::assertInstanceOf(Fuzzphony::class, $fuzzphony);

        return $fuzzphony;
    }

    public function testEachTenantFindsItsOwnTicketsAndTheRequestCannotChooseTheTenant(): void
    {
        // region: isolation
        $ada = new AppUser(new Account(1), 'ada');   // tenant 1
        $bob = new AppUser(new Account(2), 'bob');   // tenant 2

        [$status, $ids] = $this->get($ada, 'invoice');
        self::assertSame([200, [1, 3]], [$status, $ids]);   // her tenant's tickets only
        [$status, $ids] = $this->get($bob, 'invoice');
        self::assertSame([200, [4]], [$status, $ids]);      // the same word, another tenant, other rows
        // endregion

        [, $ids] = $this->get($ada, 'invoice', extra: 'tenant=2&account_id=2&account.id=2');
        self::assertSame([1, 3], $ids, 'nothing in the request reaches the tenant: it comes from the Security token');
    }

    public function testRefusalsAre403AndEveryOneIsInTheSearchLog(): void
    {
        // region: refusals
        $eve = new AppUser(new Account(1), 'eve', ['ROLE_GUEST']);   // signed in, but not ROLE_USER: the voter says no

        [$status] = $this->get(null, 'invoice');
        self::assertSame(403, $status);   // nobody is logged in: no tenant, ActorRequired
        [$status] = $this->get($eve, 'invoice');
        self::assertSame(403, $status);   // AccessDenied
        // endregion

        self::assertSame(
            [[null, null, 'invoice', 'denied'], ['1', 'eve', 'invoice', 'denied']],
            array_map(static fn(array $row): array => [$row['tenant'], $row['actor_user'], $row['query'], $row['status']], $this->logRows()),
        );
    }

    public function testInYourOwnCodeTheActorIsExplicit(): void
    {
        $fuzzphony = $this->fuzzphony();

        try {
            $fuzzphony->in('tickets')->query('invoice')->get();
            self::fail('A tenant-scoped index refuses a search without a tenant.');
        } catch (InvalidQuery $e) {
            self::assertStringContainsString('requires forTenant()', $e->getMessage());
            self::assertNotInstanceOf(ActorRequired::class, $e);
        }
        $this->expectException(ActorRequired::class);
        $fuzzphony->in('tickets')->asActor(new Actor(user: 'ada'))->query('invoice')->get();
    }

    public function testAFieldOnlyTheSupportLeadMaySearchIsNotMatchedForAnyoneElse(): void
    {
        // region: field
        $hana = new AppUser(new Account(1), 'hana', ['ROLE_USER', 'ROLE_SUPPORT_LEAD']);

        [, $ids] = $this->get($hana, 'refund');
        self::assertSame([1], $ids);    // "refund" is only in internal_note of ticket 1, and she may search that field
        [, $ids] = $this->get(self::ada(), 'refund');
        self::assertSame([], $ids);     // ada may not: the word finds nothing, ranks nothing and highlights nothing
        // endregion
    }

    public function testWhatARestrictedViewerStillLearns(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $ada = self::ada();
        $tokens->setToken(new UsernamePasswordToken($ada, 'main', $ada->getRoles()));
        $actor = new Actor(tenant: 1, user: 'ada');

        // region: not-hidden
        // the field exists: a hidden field gives no "Unknown field" warning, a field that does not exist does
        $hidden = $this->fuzzphony()->in('tickets')->asActor($actor)->query('internal_note:refund')->get();
        $unknown = $this->fuzzphony()->in('tickets')->asActor($actor)->query('nosuchfield:refund')->get();
        self::assertSame([], $hidden->warnings);
        self::assertNotSame([], $unknown->warnings);

        // filters and facets are not restricted: she counts the statuses of her tenant's tickets, whatever the fields say
        $counted = $this->fuzzphony()->in('tickets')->asActor($actor)->facets('status')->get();
        self::assertSame(['open' => 2, 'closed' => 1], array_column(array_map(static fn($f): array => [$f->value, $f->count], $counted->facets['status']), 1, 0));

        // a tenant-scoped index never suggests a spelling or a completion: its vocabulary is every tenant's words
        self::assertNull($this->fuzzphony()->in('tickets')->asActor($actor)->query('invoise')->get()->didYouMean);
        self::assertSame([], $this->fuzzphony()->suggest('tickets', 'invo', actor: $actor));
        // endregion
        $tokens->setToken(null);
    }

    public function testTheRateLimitIsPerUserAndADeniedSearchNeverSpendsAToken(): void
    {
        // region: rate-limit
        $ada = new AppUser(new Account(1), 'ada');
        $bob = new AppUser(new Account(2), 'bob');
        $eve = new AppUser(new Account(1), 'eve', ['ROLE_GUEST']);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $this->get($ada, 'invoice')[0]);   // the limit is 5 a minute
        }
        [$status, , $response] = $this->get($ada, 'invoice');
        self::assertSame(429, $status);
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));

        self::assertSame(200, $this->get($bob, 'invoice')[0]);       // per: user, so bob has a bucket of his own

        for ($i = 0; $i < 8; ++$i) {
            self::assertSame(403, $this->get($eve, 'invoice')[0]);   // denied, never 429: authorization runs first and spends no token
        }
        // endregion

        $pool = self::getContainer()->get('recipe.limiter_pool');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        self::assertTrue($pool->hasItem(sha1('search-ada')), 'the state is in the pool named in framework.rate_limiter, not in cache.rate_limiter');
        self::assertFalse($pool->hasItem(sha1('search-eve')));
        $statuses = [];
        foreach ($this->logRows() as $row) {
            $status = $row['status'];
            self::assertIsString($status);
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        self::assertSame(['ok' => 6, 'rate_limited' => 1, 'denied' => 8], $statuses);
    }

    public function testTheSearchLogKeepsTenantUserAndAMaskedAddressAndRedactsWhatWasTyped(): void
    {
        $this->get(self::ada(), 'invoice for ada@example.com', '203.0.113.45');
        $this->get(new AppUser(new Account(2), 'bob'), 'invoice', '2001:db8:abcd:12::1');

        // region: log
        $rows = $this->logRows();
        self::assertSame(
            [
                ['1', 'ada', '203.0.113.0', 'invoice for [redacted]', 'ok'],     // the e-mail address never reached the table
                ['2', 'bob', '2001:db8:abcd::', 'invoice', 'ok'],                // the last octet is zeroed; an IPv6 address is cut to its first 48 bits
            ],
            array_map(static fn(array $row): array => [$row['tenant'], $row['actor_user'], $row['actor_ip'], $row['query'], $row['status']], $rows),
        );
        // endregion
    }

    public function testTheLogIsWrittenAfterTheResponseHasBeenSent(): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $ada = self::ada();
        $tokens->setToken(new UsernamePasswordToken($ada, 'main', $ada->getRoles()));
        $kernel = $this->kernel();
        $request = Request::create('/tickets/search', 'GET', ['q' => 'invoice']);

        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->logRows(), 'the search has been answered and nothing is written yet');

        $kernel->terminate($request, $response);
        self::assertCount(1, $this->logRows());
        $tokens->setToken(null);
    }

    public function testReportsPerTenantAndTheOperatorView(): void
    {
        $this->get(self::ada(), 'invoice', '203.0.113.45');
        $this->get(self::ada(), 'invoice', '203.0.113.45');
        $this->get(new AppUser(new Account(1), 'hana', ['ROLE_USER', 'ROLE_SUPPORT_LEAD']), 'refund', '198.51.100.7');
        $this->get(new AppUser(new Account(2), 'bob'), 'export', '192.0.2.9');
        $analytics = $this->fuzzphony()->analytics();
        $since = new \DateTimeImmutable('-1 hour');

        // region: reports
        // what tenant 1 searched for: its own rows only
        $top = $analytics->topQueries('tickets', $since, tenant: 1);
        self::assertSame(['invoice' => 2, 'refund' => 1], array_column(array_map(static fn($s): array => [$s->query, $s->searches], $top), 1, 0));

        // who searched what, newest first: the rows of one tenant, however the users are named
        $recent = $analytics->recentSearches('tickets', $since, tenant: 1);
        self::assertSame(
            [['hana', '198.51.100.0', 'refund'], ['ada', '203.0.113.0', 'invoice'], ['ada', '203.0.113.0', 'invoice']],
            array_map(static fn($s): array => [$s->user, $s->ip, $s->query], $recent),
        );
        self::assertSame([], $analytics->recentSearches('tickets', $since, tenant: 2, user: 'ada'));   // ada has no rows in tenant 2
        self::assertSame(RecordStatus::Ok, $recent[0]->status);

        // a report across tenants is a decision, not a default
        try {
            $analytics->recentSearches('tickets', $since);
            self::fail('A tenant-scoped index needs a tenant or allTenants: true.');
        } catch (InvalidArgument) {
        }
        self::assertCount(4, $analytics->recentSearches('tickets', $since, allTenants: true));   // the operator's view
        // endregion
    }

    public function testTheConsoleReportsAndTheRetentionPurge(): void
    {
        $this->get(self::ada(), 'invoice', '203.0.113.45');
        $this->get(new AppUser(new Account(1), 'eve', ['ROLE_GUEST']), 'mouse', '198.51.100.7');

        $tester = $this->console();
        self::assertSame(0, $tester->run(['command' => 'fuzzphony:analytics', 'index' => 'tickets', '--recent' => true, '--tenant' => '1', '--since' => '1h'], ['decorated' => false]), $tester->getDisplay());
        RecipeOutput::assertShown(__DIR__ . '/expected/recent.txt', $tester->getDisplay());

        $refused = $this->console();
        self::assertNotSame(0, $refused->run(['command' => 'fuzzphony:analytics', 'index' => 'tickets', '--recent' => true], ['decorated' => false]));
        self::assertStringContainsString('tenant', $refused->getDisplay(), 'a tenant-scoped index needs --tenant or --all-tenants');

        PostgresTestCase::connect()->execute("UPDATE fuzzphony_search_log SET at = now() - interval '45 days' WHERE query = 'mouse'");
        $purge = $this->console();
        self::assertSame(0, $purge->run(['command' => 'fuzzphony:search-log:purge'], ['decorated' => false]), $purge->getDisplay());
        RecipeOutput::assertShown(__DIR__ . '/expected/purge.txt', $purge->getDisplay());
        self::assertSame(['invoice'], array_column($this->logRows(), 'query'), 'the row older than retention_days, with its user and address, is gone');
    }
}
