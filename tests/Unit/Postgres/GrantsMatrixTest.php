<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Inspection\CheckStatus;
use Fuzzphony\Engine\Postgres\Inspection\PrivilegeCheck;
use Fuzzphony\Engine\Postgres\Schema\Grant;
use Fuzzphony\Engine\Postgres\Schema\GrantRole;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use PHPUnit\Framework\TestCase;

/** The least-privilege matrix (docs/roles.md) as exact statements, and the doctor's wording of what a role lacks. */
final class GrantsMatrixTest extends TestCase
{
    private static function index(string $level, string $sync, bool $fuzzy = false): IndexDefinition
    {
        return IndexDefinition::builder('t')->fromTable('tt')->watch('tt')->watch('tu')->field('name', 'A', fuzzy: $fuzzy)->sync($sync)->triggerLevel($level)->build();
    }

    /**
     * @param list<Grant> $grants
     *
     * @return list<string>
     */
    private static function sql(array $grants): array
    {
        return array_map(static fn(Grant $g): string => $g->sql('r') . ($g->optional ? ' [optional]' : ''), $grants);
    }

    public function testTheSearchRoleOfARowLevelQueueAndOfAStatementLevelQueue(): void
    {
        $grants = new Grants(new Names('public', 'fz_s'), false);
        $head = ['GRANT USAGE ON SCHEMA "fz_s" TO "r";', 'GRANT USAGE ON SCHEMA "public" TO "r";', 'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_norm"(text) TO "r";', 'GRANT SELECT ON TABLE "fz_s"."fuzzphony_t" TO "r";'];

        self::assertSame([...$head, 'GRANT INSERT, UPDATE ON TABLE "fz_s"."fuzzphony_queue" TO "r";'], self::sql($grants->for(GrantRole::Search, [self::index('row', 'queue')])));
        self::assertSame([...$head, 'GRANT INSERT ON TABLE "fz_s"."fuzzphony_watch_log" TO "r";'], self::sql($grants->for(GrantRole::Search, [self::index('statement', 'queue')])));
        self::assertSame($head, self::sql($grants->for(GrantRole::Search, [self::index('row', 'manual')])), 'no queue, no shared trigger: nothing to append to');
    }

    public function testTheSearchRoleReadsTheSourceOnlyForAnIndexWithReturnableColumns(): void
    {
        $grants = new Grants(new Names('public', 'fz_s'), false);
        $plain = self::index('statement', 'manual');
        $returning = $plain->withReturnable(['name']);

        self::assertNotContains('GRANT SELECT ON TABLE "tt" TO "r"; [optional]', self::sql($grants->for(GrantRole::Search, [$plain])));
        self::assertSame(
            ['GRANT SELECT ON TABLE "tt" TO "r"; [optional]', 'GRANT SELECT ON TABLE "tu" TO "r"; [optional]'],
            array_slice(self::sql($grants->for(GrantRole::Search, [$returning])), 4),
        );
    }

    public function testTheSearchRoleOfATriggerSyncAndOfATypoTolerantIndexWithTheSearchLog(): void
    {
        $grants = new Grants(new Names('public', 'fz_s'), true);

        self::assertSame([
            'GRANT USAGE ON SCHEMA "fz_s" TO "r";',
            'GRANT USAGE ON SCHEMA "public" TO "r";',
            'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_norm"(text) TO "r";',
            'GRANT SELECT ON TABLE "fz_s"."fuzzphony_t" TO "r";',
            'GRANT SELECT ON TABLE "fz_s"."fuzzphony_t__vocab" TO "r";',
            'GRANT INSERT, UPDATE, DELETE ON TABLE "fz_s"."fuzzphony_t" TO "r";',
            'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_refresh_t"(bigint[]) TO "r";',
            'GRANT SELECT ON TABLE "tt" TO "r"; [optional]',
            'GRANT SELECT ON TABLE "tu" TO "r"; [optional]',
            'GRANT INSERT ON TABLE "fz_s"."fuzzphony_search_log" TO "r";',
        ], self::sql($grants->for(GrantRole::Search, [self::index('statement', 'trigger', fuzzy: true)])));
    }

    public function testTheWorkerRoleOfATypoTolerantIndex(): void
    {
        $grants = new Grants(new Names('public', 'fz_s'), false);

        self::assertSame([
            'GRANT CONNECT ON DATABASE "db" TO "r";',
            'GRANT USAGE ON SCHEMA "fz_s" TO "r";',
            'GRANT USAGE ON SCHEMA "public" TO "r";',
            'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_norm"(text) TO "r";',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "fz_s"."fuzzphony_queue" TO "r";',
            'GRANT SELECT, UPDATE, DELETE ON TABLE "fz_s"."fuzzphony_watch_log" TO "r";',
            'GRANT SELECT ON TABLE "fz_s"."fuzzphony_watches" TO "r";',
            'GRANT UPDATE ("cur_xid", "bad_rows", "bad_at") ON TABLE "fz_s"."fuzzphony_watches" TO "r";',
            'GRANT SELECT, UPDATE ON TABLE "fz_s"."fuzzphony_meta" TO "r";',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "fz_s"."fuzzphony_t" TO "r";',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "fz_s"."fuzzphony_t__vocab" TO "r";',
            'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_refresh_t"(bigint[]) TO "r";',
            'GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_drain_t"() TO "r";',
            'GRANT SELECT ON TABLE "tt" TO "r"; [optional]',
            'GRANT SELECT ON TABLE "tu" TO "r"; [optional]',
        ], self::sql($grants->for(GrantRole::Worker, [self::index('statement', 'queue', fuzzy: true)], 'db')));
    }

    public function testTheAdminRoleAsksForTheDatabaseOnlyOnAnotherSchemaAndOnlyWithADatabaseName(): void
    {
        $index = self::index('statement', 'queue');
        $tables = ['GRANT SELECT, TRIGGER ON TABLE "tt" TO "r"; [optional]', 'GRANT SELECT, TRIGGER ON TABLE "tu" TO "r"; [optional]'];

        self::assertSame(['GRANT CONNECT ON DATABASE "db" TO "r";', 'GRANT USAGE ON SCHEMA "fz_s" TO "r";', 'GRANT USAGE ON SCHEMA "public" TO "r";', 'GRANT CREATE ON DATABASE "db" TO "r";', 'GRANT CREATE ON SCHEMA "fz_s" TO "r";', ...$tables], self::sql((new Grants(new Names('public', 'fz_s'), false))->for(GrantRole::Admin, [$index], 'db')));
        self::assertSame(['GRANT USAGE ON SCHEMA "fz_s" TO "r";', 'GRANT USAGE ON SCHEMA "public" TO "r";', 'GRANT CREATE ON SCHEMA "fz_s" TO "r";', ...$tables], self::sql((new Grants(new Names('public', 'fz_s'), false))->for(GrantRole::Admin, [$index], '')), 'no database named: nothing on a database');
        self::assertSame(['GRANT CONNECT ON DATABASE "db" TO "r";', 'GRANT USAGE ON SCHEMA "public" TO "r";', 'GRANT CREATE ON SCHEMA "public" TO "r";', ...$tables], self::sql((new Grants(new Names('public', 'public'), false))->for(GrantRole::Admin, [$index], 'db')), 'the public schema needs no CREATE on the database');
    }

    public function testTheTablesTheAdminOwnsHaveNoDuplicates(): void
    {
        $grants = new Grants(new Names('public', 'fz_s'), true);
        $index = self::index('statement', 'queue', fuzzy: true);

        self::assertSame(
            ['"fz_s"."fuzzphony_queue"', '"fz_s"."fuzzphony_meta"', '"fz_s"."fuzzphony_watch_log"', '"fz_s"."fuzzphony_watches"', '"fz_s"."fuzzphony_t"', '"fz_s"."fuzzphony_t__vocab"', '"fz_s"."fuzzphony_search_log"'],
            $grants->owned([$index, $index]),
        );
        self::assertSame(['"fz_s"."fuzzphony_queue"', '"fz_s"."fuzzphony_meta"', '"fz_s"."fuzzphony_watch_log"', '"fz_s"."fuzzphony_watches"', '"fz_s"."fuzzphony_t"'], (new Grants(new Names('public', 'fz_s'), false))->owned([self::index('row', 'queue')]));
    }

    public function testASourceTableThatIsAlsoWatchedIsGrantedOnce(): void
    {
        $grants = (new Grants(new Names('public', 'fz_s'), false))->for(GrantRole::Admin, [self::index('statement', 'queue')]);

        self::assertSame(2, count(array_filter($grants, static fn(Grant $g): bool => $g->kind === Grant::TABLE)), 'tt (the source and a watch) once, tu once');
    }

    public function testAGrantListsItsColumnsQuotedAndAsksTheCatalogForTheRightKind(): void
    {
        self::assertSame('GRANT SELECT ("c 1", "c2") ON TABLE "a"."b" TO "x";', (new Grant(Grant::TABLE, '"a"."b"', ['SELECT'], 'w', false, ['c 1', 'c2']))->sql('x'));
        self::assertSame('GRANT SELECT, INSERT ON TABLE "a"."b" TO "x";', (new Grant(Grant::TABLE, '"a"."b"', ['SELECT', 'INSERT'], 'w'))->sql('x'));
        self::assertSame(
            "SELECT NOT has_schema_privilege('bob', to_regnamespace('\"a\"')::oid, 'USAGE') WHERE to_regnamespace('\"a\"') IS NOT NULL",
            (new Grant(Grant::SCHEMA, '"a"', ['USAGE'], 'w'))->missing('USAGE', "'bob'"),
        );
        self::assertSame("SELECT NOT has_database_privilege('bob', current_database(), 'CONNECT')", (new Grant(Grant::DATABASE, '"d"', ['CONNECT'], 'w'))->missing('CONNECT', "'bob'"));
        self::assertSame(
            "SELECT NOT has_function_privilege('bob', to_regprocedure('\"a\".\"f\"(text)'), 'EXECUTE') WHERE to_regprocedure('\"a\".\"f\"(text)') IS NOT NULL",
            (new Grant(Grant::FUNCTION, '"a"."f"(text)', ['EXECUTE'], 'w'))->missing('EXECUTE', "'bob'"),
        );
    }

    /** @param array<string, bool> $lacking the role => whether the probe answers that it lacks every privilege */
    private static function probe(array $lacking): Connection
    {
        return new class ($lacking) implements Connection {
            /** @param array<string, bool> $lacking */
            public function __construct(private array $lacking) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                if ($sql === 'SELECT current_database()') {
                    return 'db';
                }
                foreach ($this->lacking as $role => $lacks) {
                    if (str_contains($sql, "'" . $role . "'")) {
                        return $lacks && str_contains($sql, 'SELECT NOT') ? true : null;
                    }
                }

                return null;
            }

            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
    }

    private static function session(string $user): Connection
    {
        return new class ($user) implements Connection {
            public function __construct(private string $user) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return $this->user;
            }

            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback($this);
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
    }

    public function testTheDoctorReportsTheSecondRoleAfterAFirstOneThatLacksNothing(): void
    {
        $check = new PrivilegeCheck(self::probe(['alice' => false, 'bob' => true]), new Grants(new Names('public', 'fz_s'), false), ['search' => self::session('alice'), 'worker' => self::session('bob'), 'other' => self::session('eve')]);

        $result = $check->check(self::index('statement', 'queue'));

        self::assertSame(CheckStatus::Error, $result->status);
        self::assertSame(
            'worker "bob" lacks CONNECT on database "db"; USAGE on schema "fz_s"; USAGE on schema "public"; EXECUTE on function "fz_s"."fuzzphony_norm"(text); '
            . 'SELECT, INSERT, UPDATE, DELETE on table "fz_s"."fuzzphony_queue"; SELECT, UPDATE, DELETE on table "fz_s"."fuzzphony_watch_log"; SELECT on table "fz_s"."fuzzphony_watches"; '
            . 'UPDATE on table "fz_s"."fuzzphony_watches"; SELECT, UPDATE on table "fz_s"."fuzzphony_meta"; SELECT, INSERT, UPDATE, DELETE on table "fz_s"."fuzzphony_t"; '
            . 'EXECUTE on function "fz_s"."fuzzphony_refresh_t"(bigint[]); EXECUTE on function "fz_s"."fuzzphony_drain_t"(); SELECT on table "tt"; SELECT on table "tu".',
            $result->message,
        );
        self::assertSame('run the output of bin/console fuzzphony:schema t --dump-grants --role=worker --to=bob as the owner of the objects (docs/roles.md)', $result->fix);
    }

    public function testTheDoctorSaysWhoHoldsWhatTheyNeed(): void
    {
        $probe = self::probe(['alice' => false, 'bob' => false]);
        $grants = new Grants(new Names('public', 'fz_s'), false);

        $ok = (new PrivilegeCheck($probe, $grants, ['search' => self::session('alice'), 'worker' => self::session('bob')]))->check(self::index('statement', 'queue'));
        self::assertSame(CheckStatus::Ok, $ok->status);
        self::assertSame('hold what they need (search: alice, worker: bob)', $ok->message);

        $none = (new PrivilegeCheck($probe, $grants, ['worker' => self::session('bob')]))->check(self::index('statement', 'manual'));
        self::assertSame('nothing to check', $none->message, 'a worker is not asked about an index without the queue');
    }

    public function testTheAdminIsAskedAboutOwnershipOfTheIndexTables(): void
    {
        $result = (new PrivilegeCheck(self::probe(['carol' => true]), new Grants(new Names('public', 'fz_s'), false), ['admin' => self::session('carol')]))->check(self::index('statement', 'queue'));

        self::assertStringContainsString('ownership of "fz_s"."fuzzphony_t"', $result->message);
        self::assertStringContainsString('ownership of "fz_s"."fuzzphony_queue"', $result->message);
    }

    public function testTheWorkerAndTheAdminReadEveryTableOfEverySourceOfAMultiSourceIndex(): void
    {
        $index = IndexDefinition::builder('site')
            ->sourceFromTable('article', 'art')
            ->source('product', 'SELECT id, name FROM prod JOIN brand ON true', watch: [new \Fuzzphony\Core\Definition\Watch('prod'), new \Fuzzphony\Core\Definition\Watch('brand', 'SELECT id FROM prod WHERE brand_id = :id')])
            ->field('name', 'A')
            ->build();
        $grants = new Grants(new Names('public', 'fz_s'), false);

        $worker = self::sql($grants->for(GrantRole::Worker, [$index]));
        $admin = self::sql($grants->for(GrantRole::Admin, [$index]));

        foreach (['art', 'prod', 'brand'] as $table) {
            self::assertContains(sprintf('GRANT SELECT ON TABLE "%s" TO "r"; [optional]', $table), $worker);
            self::assertContains(sprintf('GRANT SELECT, TRIGGER ON TABLE "%s" TO "r"; [optional]', $table), $admin);
        }
        self::assertContains('GRANT EXECUTE ON FUNCTION "fz_s"."fuzzphony_refresh_site"(text[]) TO "r";', $worker);
    }
}
