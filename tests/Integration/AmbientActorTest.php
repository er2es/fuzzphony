<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Unit\Core\Search\RecordingSearchLog;
use PHPUnit\Framework\TestCase;

/**
 * The actor of a search that names none is the application's current one (the ambient actor): a controller that forgets
 * asActor() is guarded like one that remembers, CLI and workers are anonymous (the "granted" field stays hidden), and only
 * the visible asSystem() sees everything. "secret" is the granted field; "boss" is the only user who holds it.
 */
final class AmbientActorTest extends TestCase
{
    private Connection $connection;
    private Actor $current;
    private bool $thereIsAnApplication = true;
    private RecordingSearchLog $log;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        $this->connection->execute('DROP TABLE IF EXISTS fz_amb_doc, fuzzphony_amb_docs, fuzzphony_amb_docs__vocab, fuzzphony_amb_docs__next, fuzzphony_amb_docs__changes, fuzzphony_amb_tenant, fuzzphony_amb_tenant__vocab, fuzzphony_amb_tenant__next, fuzzphony_amb_tenant__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_amb_doc (id bigint PRIMARY KEY, title text NOT NULL, secret text NOT NULL, tid int NOT NULL)');
        foreach ([[1, 'Plain notebook', 'margin zebrafish', 1], [2, 'Zebrafish poster', '', 1], [3, 'Desk lamp', 'zebrafish zebrafish lamp', 2]] as [$id, $title, $secret, $tid]) {
            $this->connection->execute('INSERT INTO fz_amb_doc VALUES (:id, :t, :s, :tid)', ['id' => $id, 't' => $title, 's' => $secret, 'tid' => $tid]);
        }
        $this->current = Actor::anonymous();
        $this->thereIsAnApplication = true;
        $this->log = new RecordingSearchLog();
        $this->build()->schema()->apply($this->connection);
        $this->build()->reindex('amb_docs');
        $this->build()->reindex('amb_tenant');
    }

    public function currentActor(): Actor
    {
        return $this->thereIsAnApplication ? $this->current : Actor::anonymous();
    }

    private function build(bool $allowSystem = true): Fuzzphony
    {
        $docs = IndexDefinition::builder('amb_docs')
            ->fromQuery('SELECT id, title, secret FROM fz_amb_doc')
            ->field('title', 'A', fuzzy: true)
            ->field('secret', 'C', granted: 'ROLE_BOSS')
            ->language('english')
            ->sync('manual')
            ->build();
        $tenant = IndexDefinition::builder('amb_tenant')
            ->fromQuery('SELECT id, title, tid FROM fz_amb_doc')
            ->field('title', 'A')
            ->filter('tid', 'int')
            ->tenant('tid')
            ->language('english')
            ->sync('manual')
            ->build();

        $guard = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return $actor->user === 'boss' ? Access::all() : Access::only(array_values(array_diff($index->fieldNames(), ['secret'])));
            }
        };
        $resolver = new class ($this) implements ActorResolver {
            public function __construct(private AmbientActorTest $test) {}

            public function resolve(): Actor
            {
                return $this->test->currentActor();
            }
        };

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$docs, $tenant]), new SearchGate(log: $this->log, guards: [$guard], actors: $resolver, allowSystem: $allowSystem));
    }

    /** @return list<int|string> */
    private function ids(SearchBuilder $search): array
    {
        $ids = $search->get()->ids();
        sort($ids);

        return $ids;
    }

    public function testAForgottenAsActorSearchesAsTheCurrentUser(): void
    {
        $fz = $this->build();
        $this->current = new Actor(user: 'ada');
        self::assertSame([2], $this->ids($fz->in('amb_docs')->query('zebrafish')), 'the forgetful controller of a user without the role does not see the granted field');

        $this->current = new Actor(user: 'boss');
        self::assertSame([1, 2, 3], $this->ids($fz->in('amb_docs')->query('zebrafish')), 'the user who holds the role does');
    }

    public function testAnExplicitActorWinsOverTheCurrentUser(): void
    {
        $fz = $this->build();
        $this->current = new Actor(user: 'boss');
        self::assertSame([2], $this->ids($fz->in('amb_docs')->query('zebrafish')->asActor(new Actor(user: 'ada'))));
        $this->current = new Actor(user: 'ada');
        self::assertSame([1, 2, 3], $this->ids($fz->in('amb_docs')->query('zebrafish')->asActor(new Actor(user: 'boss'))));
    }

    public function testWithoutAnyoneTheActorIsAnonymousAndTheGrantedFieldIsHidden(): void
    {
        $fz = $this->build();
        $this->thereIsAnApplication = false; // a command, a worker, a test: nobody to resolve
        $result = $fz->in('amb_docs')->query('zebrafish')->get();

        self::assertSame([2], $result->ids());
        self::assertSame(1, $result->total);
        self::assertSame([2], $fz->in('amb_docs')->query('zebrafsh')->thresholds(['typo' => ['mode' => 'always']])->get()->ids(), 'typo tolerance does not reach the hidden field either');
        self::assertNull($fz->in('amb_docs')->query('zebrafish')->highlight('title')->get()->didYouMean);
    }

    public function testAsSystemSeesEverythingAndIsLoggedAsTheSystem(): void
    {
        $fz = $this->build();
        $this->thereIsAnApplication = false;
        self::assertSame([1, 2, 3], $this->ids($fz->in('amb_docs')->query('zebrafish')->asSystem()));
        self::assertSame('(system)', $this->log->records[0]->user);
        self::assertSame(RecordStatus::Ok, $this->log->records[0]->status);

        // the last of asActor() / asSystem() wins
        self::assertSame([2], $this->ids($fz->in('amb_docs')->query('zebrafish')->asSystem()->asActor(new Actor(user: 'ada'))));
        self::assertSame([1, 2, 3], $this->ids($fz->in('amb_docs')->query('zebrafish')->asActor(new Actor(user: 'ada'))->asSystem()));
    }

    public function testSystemCanBeSwitchedOff(): void
    {
        $fz = $this->build(allowSystem: false);
        try {
            $fz->in('amb_docs')->query('zebrafish')->asSystem()->get();
            self::fail('AccessDenied expected');
        } catch (AccessDenied $e) {
            self::assertStringContainsString('allow_system', $e->getMessage());
        }
        self::assertSame(RecordStatus::Denied, $this->log->records[0]->status, 'a refusal is recorded');
        $this->current = new Actor(user: 'ada');
        self::assertSame([2], $this->ids($fz->in('amb_docs')->query('zebrafish')), 'ordinary searches are not affected');
    }

    public function testATenantScopedIndexStillNeedsATenant(): void
    {
        $fz = $this->build();
        $this->thereIsAnApplication = false;
        foreach ([fn() => $fz->in('amb_tenant')->query('desk')->get(), fn() => $fz->in('amb_tenant')->query('desk')->asSystem()->get()] as $search) {
            try {
                $search();
                self::fail('ActorRequired expected');
            } catch (ActorRequired) {
            }
        }
        $this->current = new Actor(tenant: 2, user: 'ada');
        $this->thereIsAnApplication = true;
        self::assertSame([3], $fz->in('amb_tenant')->query('desk')->get()->ids(), 'the tenant of the current user scopes the search');
        self::assertSame([], $fz->in('amb_tenant')->query('notebook')->get()->ids(), 'and another tenant\'s document is not found');
        self::assertSame([1], $fz->in('amb_tenant')->query('notebook')->forTenant(1)->get()->ids(), 'an explicit forTenant() is kept');
        self::assertSame([3], $fz->in('amb_tenant')->query('desk')->asSystem()->forTenant(2)->get()->ids());
    }

    public function testFederatedSearchesAreGuardedToo(): void
    {
        $fz = $this->build();
        $this->current = new Actor(user: 'ada');
        self::assertSame(1, $fz->federated()->index('amb_docs')->query('zebrafish')->get()->total, 'ambient');
        $this->current = new Actor(user: 'boss');
        self::assertSame(3, $fz->federated()->index('amb_docs')->query('zebrafish')->get()->total, 'ambient, the role holder');
        self::assertSame(1, $fz->federated()->index('amb_docs')->query('zebrafish')->asActor(new Actor(user: 'ada'))->get()->total, 'explicit');
        $this->thereIsAnApplication = false;
        self::assertSame(1, $fz->federated()->index('amb_docs')->query('zebrafish')->get()->total, 'anonymous');
        self::assertSame(3, $fz->federated()->index('amb_docs')->query('zebrafish')->asSystem()->get()->total, 'system');
        self::assertSame(1, $fz->federated()->index('amb_docs')->query('zebrafish')->asSystem()->asActor(new Actor(user: 'ada'))->get()->total, 'the last one wins');
    }

    public function testCompletionsFollowTheSameRules(): void
    {
        $fz = $this->build();
        $fz->rebuildVocabulary('amb_docs');
        $this->current = new Actor(user: 'ada');
        self::assertSame([], $fz->complete('amb_docs', 'zebraf'), 'the vocabulary holds the words of the granted field');
        self::assertSame(['zebrafish'], $fz->complete('amb_docs', 'zebraf', system: true));
        self::assertSame(['zebrafish'], $fz->complete('amb_docs', 'zebraf', actor: new Actor(user: 'boss')));
    }

    public function testAGrantedFieldWithoutAGuardDoesNotBuild(): void
    {
        $index = IndexDefinition::builder('amb_docs')->fromQuery('SELECT id, title, secret FROM fz_amb_doc')->field('title', 'A')->field('secret', 'C', granted: 'ROLE_BOSS')->sync('manual')->build();
        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('no guard');
        Fuzzphony::postgres($this->connection, [$index]);
    }
}
