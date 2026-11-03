<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\RecordStatus;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use PHPUnit\Framework\TestCase;

/** The two public ways in without Symfony: Fuzzphony::postgres() and Fuzzphony::wizard(). */
final class FactoryAndWizardFacadeTest extends TestCase
{
    public function testThePostgresFactoryBuildsAWorkingServiceWithoutAnEngineInUserCode(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $fuzzphony = Fuzzphony::postgres($connection, [self::index()]);
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('factory_products');

        self::assertContains(2, $fuzzphony->in('factory_products')->query('razer')->get()->ids());
        self::assertTrue($fuzzphony->inspect('factory_products')->isHealthy());
        $fuzzphony->resetCaches();
        self::assertSame(['wireless'], $fuzzphony->complete('factory_products', 'wire'));
    }

    public function testTheFactoryWiresGuardsAndTheSearchLog(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $deny = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                throw new AccessDenied('no');
            }
        };
        $fuzzphony = Fuzzphony::postgres($connection, [self::index()], searchLog: true, redact: ['email'], ipMode: IpMode::Masked, guards: [$deny]);
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('factory_products');

        try {
            $fuzzphony->in('factory_products')->query('razer')->asActor(new Actor(user: 'ada'))->get();
            self::fail('the guard refuses');
        } catch (AccessDenied) {
        }

        $logged = $fuzzphony->analytics()->recentSearches('factory_products', new \DateTimeImmutable('-1 hour'));
        self::assertNotSame([], $logged, 'the refusal is in the table the factory keeps');
        self::assertSame(RecordStatus::Denied, $logged[0]->status);
    }

    public function testTheWizardFacadeSuggestsAndExports(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $wizard = Fuzzphony::postgres($connection, [])->wizard();

        self::assertContains('fz_product', array_column($wizard->tables(), 'table'));
        $suggestion = $wizard->suggest('fz_product', 'wizard_facade');
        self::assertNotNull($suggestion->definition, implode("\n", $suggestion->notes));
        self::assertStringContainsString('wizard_facade', $wizard->yaml($suggestion->definition));
        self::assertStringContainsString('IndexDefinition::builder', $wizard->builder($suggestion->definition));
        self::assertNull($wizard->attributes($suggestion->definition), 'a joined source has no attribute form');
        $plain = IndexDefinition::builder('plain')->fromTable('fz_brand')->field('name', 'A')->build();
        self::assertStringContainsString('Searchable', (string) $wizard->attributes($plain, 'Brand'));
    }

    private static function index(): IndexDefinition
    {
        return IndexDefinition::builder('factory_products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', 'B')
            ->build();
    }
}
