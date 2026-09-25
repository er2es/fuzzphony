<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * pg_trgm and unaccent do not have to be on the search_path: every generated statement that uses
 * one of their operators or functions schema-qualifies it explicitly (FuzzyQueryCompiler's "<%"
 * and word_similarity(), PostgresSchemaGenerator's gin_trgm_ops and unaccent() calls). This
 * reinstalls both extensions into a schema deliberately left off the search_path and runs an
 * exact, a typo-tolerant (fuzzy) and a relaxed search through it, then restores both extensions
 * into "public" in a finally block, since every other integration test in this suite assumes they
 * live there (see PostgresEngineTest::testDoctorReportsAMissingRequiredExtension for the same
 * drop / restore pattern).
 */
final class ExtensionSchemaTest extends TestCase
{
    private const string SCHEMA = 'fuzzphony_ext_test';

    public function testSearchWorksWithTheExtensionsOutsideTheSearchPath(): void
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());

        // Both extensions are database-wide (one instance each, whatever schema they are in), so
        // moving them means dropping the "public" ones first.
        $connection->execute('DROP EXTENSION IF EXISTS pg_trgm CASCADE');
        $connection->execute('DROP EXTENSION IF EXISTS unaccent CASCADE');
        $connection->execute('CREATE SCHEMA ' . self::SCHEMA);

        try {
            // Belt and braces: the new schema was never added to it, but pin it explicitly so the
            // test does not depend on the test database's default search_path.
            $connection->execute('SET search_path TO public');

            $engine = new PostgresEngine($connection, self::SCHEMA);
            $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]));
            $fuzzphony->schema()->apply($connection); // CREATE EXTENSION ... WITH SCHEMA fuzzphony_ext_test
            $fuzzphony->reindex('products');

            $exact = $fuzzphony->in('products')->query('mouse')->get();
            self::assertContains(1, $exact->ids(), 'exact full-text match');

            $fuzzy = $fuzzphony->in('products')->query('headphnoes')->get();
            self::assertTrue($fuzzy->usedFuzzy);
            self::assertSame(3, $fuzzy->hits[0]->id ?? null, 'typo-tolerant (trigram) match');

            // "office" appears nowhere: the strict search returns nothing, so the relaxation probe
            // (also trigram-based for "wireless" / "mouse") drops it and the search succeeds anyway.
            $relaxed = $fuzzphony->in('products')->query('wireless mouse offfice')->get();
            self::assertSame([1], $relaxed->ids());
            self::assertContains('No results for all words; ignored words that match nothing: "offfice".', $relaxed->warnings);
        } finally {
            $connection->execute('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            $connection->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $connection->execute('CREATE EXTENSION IF NOT EXISTS unaccent');
        }
    }
}
