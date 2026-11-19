<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Inspection\Check;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Integration\PostgresTestCase;

/**
 * @internal Shared helpers of the schema/DDL hardening tests: a fresh database (no Fuzzphony object left from another test),
 * a catalog fingerprint to compare two applies, and the doctor's problems as strings.
 */
final class Harness
{
    /** The schemas a fresh install starts without (public is recreated empty). */
    public const array OWN_SCHEMAS = ['fuzzphony', 'fz_h_own', 'fz_h_app'];

    public static function fresh(): Connection
    {
        $c = PostgresTestCase::connect();
        foreach ($c->fetchAll('SELECT evtname FROM pg_event_trigger') as $row) {
            $c->execute(sprintf('DROP EVENT TRIGGER %s', Coerce::str($row['evtname'])));
        }
        foreach (self::OWN_SCHEMAS as $schema) {
            $c->execute(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
        }
        $c->execute('DROP SCHEMA IF EXISTS public CASCADE');
        $c->execute('CREATE SCHEMA public');
        $c->execute('GRANT USAGE ON SCHEMA public TO PUBLIC');

        return $c;
    }

    /** The fixture tables (fz_product, fz_brand) with the conformance rows, in public. */
    public static function fixtures(Connection $c): void
    {
        PostgresTestCase::createFixtures($c, EngineConformanceTestCase::fixtureRows());
    }

    /** @param list<IndexDefinition> $indexes */
    public static function fuzzphony(Connection $c, array $indexes, string $schema = 'public', string $extensionSchema = 'public'): Fuzzphony
    {
        return new Fuzzphony(new PostgresEngine($c, $extensionSchema, $schema, searchLog: true), new IndexRegistry($indexes));
    }

    /**
     * Everything an apply creates, as comparable text: tables and columns, indexes (with validity), functions (body, settings, ACL),
     * triggers, ACLs of tables and schemas, text search configurations, extensions. Rows are left out.
     *
     * @return array<string, list<string>>
     */
    public static function catalog(Connection $c): array
    {
        $ns = "n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND n.nspname NOT LIKE 'pg\\_temp\\_%' AND n.nspname NOT LIKE 'pg\\_toast\\_temp\\_%'";
        $queries = [
            'relations' => "SELECT n.nspname || '.' || c.relname || ' ' || c.relkind::text || ' ' || coalesce(c.relacl::text, '') || ' ' || c.relpersistence::text FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$ns} AND c.relkind IN ('r', 'p', 'v', 'S', 'i', 'I') AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype = 'e')",
            'columns' => "SELECT n.nspname || '.' || c.relname || '.' || a.attname || ' ' || format_type(a.atttypid, a.atttypmod) || ' ' || a.attnotnull::text || ' ' || coalesce(pg_get_expr(ad.adbin, ad.adrelid), '') FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace LEFT JOIN pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum WHERE {$ns} AND c.relkind IN ('r', 'p') AND a.attnum > 0 AND NOT a.attisdropped",
            'indexes' => "SELECT pg_get_indexdef(i.indexrelid) || ' valid=' || i.indisvalid::text || ' ready=' || i.indisready::text FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$ns}",
            'constraints' => "SELECT n.nspname || '.' || co.conrelid::regclass::text || ' ' || co.conname || ' ' || pg_get_constraintdef(co.oid) FROM pg_constraint co JOIN pg_namespace n ON n.oid = co.connamespace WHERE {$ns}",
            'functions' => "SELECT n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ') ' || md5(p.prosrc) || ' ' || coalesce(p.proconfig::text, '') || ' ' || coalesce(p.proacl::text, '') || ' ' || p.prosecdef::text || ' ' || p.provolatile::text FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE {$ns} AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')",
            'triggers' => "SELECT pg_get_triggerdef(t.oid) || ' ' || t.tgenabled::text FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE {$ns} AND NOT t.tgisinternal",
            'schemas' => "SELECT n.nspname || ' ' || coalesce(n.nspacl::text, '') FROM pg_namespace n WHERE {$ns}",
            'ts_configs' => "SELECT n.nspname || '.' || c.cfgname FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE {$ns}",
            'ts_maps' => "SELECT c.cfgname || ' ' || m.maptokentype::text || ' ' || m.mapseqno::text || ' ' || m.mapdict::regdictionary::text FROM pg_ts_config_map m JOIN pg_ts_config c ON c.oid = m.mapcfg JOIN pg_namespace n ON n.oid = c.cfgnamespace WHERE {$ns}",
            'extensions' => "SELECT e.extname || ' ' || n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace",
            'default_acl' => 'SELECT d.defaclrole::regrole::text || coalesce(d.defaclacl::text, \'\') FROM pg_default_acl d',
        ];
        $out = [];
        foreach ($queries as $key => $sql) {
            $rows = array_map(static fn(array $r): string => Coerce::str(array_values($r)[0]), $c->fetchAll($sql));
            sort($rows);
            $out[$key] = $rows;
        }

        return $out;
    }

    /** @return list<string> the doctor's errors and warnings ("name: message") */
    public static function problems(Fuzzphony $f, string $index): array
    {
        return array_map(static fn(Check $c): string => sprintf('%s %s: %s', $c->status->name, $c->name, $c->message), $f->inspect($index)->problems());
    }

    /** @return list<string> the doctor's errors only */
    public static function errors(Fuzzphony $f, string $index): array
    {
        return array_values(array_filter(self::problems($f, $index), static fn(string $p): bool => str_starts_with($p, 'Error')));
    }

    /** @return list<string> INVALID or not-ready indexes in the database */
    public static function invalidIndexes(Connection $c): array
    {
        return array_map(static fn(array $r): string => Coerce::str($r['n']), $c->fetchAll('SELECT i.indexrelid::regclass::text AS n FROM pg_index i WHERE NOT i.indisvalid OR NOT i.indisready ORDER BY 1'));
    }
}
