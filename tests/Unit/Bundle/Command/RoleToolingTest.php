<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Inspection\InspectionReport;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\Inspection\PrivilegeCheck;
use Fuzzphony\Engine\Postgres\Schema\Grants;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** fuzzphony:schema --dump-grants and the doctor's authorization and privilege rows: what an operator pastes into psql, and what fails the check. */
final class RoleToolingTest extends TestCase
{
    private static function connection(bool $exists): Connection
    {
        return new class ($exists) implements Connection {
            public function __construct(private bool $exists) {}

            public function fetchAll(string $sql, array $params = []): array
            {
                return [];
            }

            public function fetchValue(string $sql, array $params = []): mixed
            {
                return $sql === 'SELECT current_database()' ? 'appdb' : $this->exists;
            }

            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            public function transactional(callable $callback): mixed
            {
                return $callback();
            }

            public function inTransaction(): bool
            {
                return false;
            }
        };
    }

    private static function schema(bool $exists = true): CommandTester
    {
        $fuzzphony = new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products('queue')]));

        return new CommandTester(new SchemaCommand($fuzzphony, self::connection($exists), new Grants(new Names('public', 'fz'), false)));
    }

    public function testTheDumpNamesTheRolesTheirPurposeAndTheOwnershipHandOver(): void
    {
        $tester = self::schema();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump-grants' => true]));
        $out = $tester->getDisplay(true);

        foreach (['search', 'worker', 'admin'] as $role) {
            self::assertStringContainsString('-- ' . $role . ': ', $out);
            self::assertStringContainsString('"fuzzphony_' . $role . '"', $out, 'the default role name');
        }
        self::assertStringContainsString("-- CREATE ROLE \"fuzzphony_admin\" LOGIN PASSWORD '...' NOSUPERUSER", $out);
        self::assertStringContainsString('-- ALTER TABLE "fz"."fuzzphony_products" OWNER TO "fuzzphony_admin";', $out);
        self::assertStringContainsString('-- A GRANT cannot give ownership.', $out);
        self::assertStringContainsString('-- CREATE EXTENSION needs a superuser', $out);
        self::assertSame(2, substr_count($out, '-- A table created later'), 'the search and the worker roles; the admin has its own note');
        self::assertStringContainsString('-- reindex "products", and the triggers of its sync (a table of your application: skip it if it lives elsewhere)', $out);
        self::assertStringContainsString('-- Fuzzphony\'s own tables and functions' . "\n" . 'GRANT USAGE ON SCHEMA "fz" TO "fuzzphony_search";', $out, 'a table that is there needs no skip note');
        self::assertStringNotContainsString('not there yet', $out);
    }

    public function testTheDumpOfOneRoleAndTheRefusalsOfBadOptions(): void
    {
        $one = self::schema();
        self::assertSame(Command::SUCCESS, $one->execute(['--dump-grants' => true, '--role' => 'worker', '--to' => 'etl_user']));
        self::assertStringContainsString('GRANT USAGE ON SCHEMA "fz" TO "etl_user";', $one->getDisplay(true));
        self::assertStringNotContainsString('fuzzphony_search', $one->getDisplay(true));
        self::assertStringNotContainsString('-- search: ', $one->getDisplay(true));

        $unknown = self::schema();
        self::assertSame(Command::INVALID, $unknown->execute(['--dump-grants' => true, '--role' => 'root']));
        self::assertStringContainsString('Unknown role "root": use search, worker or admin.', $unknown->getDisplay(true));

        $noRole = self::schema();
        self::assertSame(Command::INVALID, $noRole->execute(['--dump-grants' => true, '--to' => 'etl_user']));
        self::assertStringContainsString('--to names one PostgreSQL role', $noRole->getDisplay(true));

        $badTo = self::schema();
        self::assertSame(Command::INVALID, $badTo->execute(['--dump-grants' => true, '--role' => 'admin', '--to' => 'a b; DROP']));
        self::assertStringContainsString('--to names one PostgreSQL role', $badTo->getDisplay(true));
    }

    public function testAnObjectThatIsNotThereYetIsSaidSo(): void
    {
        $tester = self::schema(exists: false);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dump-grants' => true, '--role' => 'search']));

        self::assertStringContainsString('-- not there yet (run fuzzphony:schema --apply, then this again): GRANT USAGE ON SCHEMA "fz" TO "fuzzphony_search";', $tester->getDisplay(true));
    }

    /** @param list<Check> $checks */
    private static function doctor(bool $authorization, array $checks, ?PrivilegeCheck $privileges = null, ?IndexDefinition $index = null): CommandTester
    {
        $engine = self::createStub(Engine::class);
        $engine->method('inspect')->willReturnCallback(static fn(IndexDefinition $i): InspectionReport => new InspectionReport($i->name, $checks));
        $index ??= Indexes::products('manual');

        return new CommandTester(new DoctorCommand(new Fuzzphony($engine, new IndexRegistry([$index])), authorizationEnabled: $authorization, privileges: $privileges));
    }

    private static function granted(): IndexDefinition
    {
        return IndexDefinition::builder('guarded')->fromTable('t')->field('name', 'A')->field('secret', 'B', granted: 'ROLE_X')->sync('manual')->build();
    }

    public function testTheDoctorFailsAGrantedFieldWithoutAuthorizationAndOnlyThen(): void
    {
        $without = self::doctor(false, [Check::ok('Table', 'ok')], index: self::granted());
        self::assertSame(Command::FAILURE, $without->execute([], ['interactive' => false]));
        self::assertStringContainsString('Authorization', $without->getDisplay(true));
        self::assertStringContainsString('Fix for "Authorization": set fuzzphony.authorization.enabled: true', $without->getDisplay(true));
        self::assertStringContainsString('every viewer would see it', preg_replace('/\s+/', ' ', str_replace('│', ' ', $without->getDisplay(true))) ?? '');

        $with = self::doctor(true, [Check::ok('Table', 'ok')], index: self::granted());
        self::assertSame(Command::SUCCESS, $with->execute([], ['interactive' => false]));
        self::assertStringNotContainsString('Authorization', $with->getDisplay(true));

        $plain = self::doctor(false, [Check::ok('Table', 'ok')]);
        self::assertSame(Command::SUCCESS, $plain->execute([], ['interactive' => false]), 'an index without a granted field needs no authorization');
    }

    public function testTheDoctorAddsThePrivilegeRowBesideTheEngineChecks(): void
    {
        $probe = self::connection(false);
        $privileges = new PrivilegeCheck($probe, new Grants(new Names('public', 'fz'), false), []);

        $tester = self::doctor(true, [Check::ok('Table', 'ok'), Check::ok('Coverage', 'complete')], $privileges);
        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => false]));
        $out = $tester->getDisplay(true);

        self::assertStringContainsString('Table', $out);
        self::assertStringContainsString('Coverage', $out, 'the engine\'s own rows stay');
        self::assertStringContainsString('Privileges', $out);
        self::assertStringContainsString('nothing to check', $out);
    }
}
