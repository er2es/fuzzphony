<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Composer\InstalledVersions;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Schema\Statement;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Schema\PostgresSchemaGenerator;
use Fuzzphony\Engine\Postgres\Sql\DocumentSql;
use Fuzzphony\Engine\Postgres\Sql\Sql;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    public function testSidecarColumnsFollowTheDefinition(): void
    {
        self::assertSame(
            ['id', 'tsv', 'fz', 'exact', 't_name', 'z_name', 't_brand', 'z_brand', 't_description', 'boost', 'recency_at', 'f_price', 'f_in_stock', 'f_published_at', 'f_brand_id', 'indexed_at'],
            array_keys((new PostgresSchemaGenerator())->columns(Indexes::products())),
        );
    }

    public function testTheRefreshFunctionFillsThePerFieldColumns(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('ALTER TABLE "public"."fuzzphony_products" ADD COLUMN IF NOT EXISTS "t_brand" tsvector NOT NULL DEFAULT \'\'', $sql);
        self::assertStringContainsString('ALTER TABLE "public"."fuzzphony_products" ADD COLUMN IF NOT EXISTS "z_brand" text NOT NULL DEFAULT \'\'', $sql);
        self::assertStringNotContainsString('"z_description"', $sql, 'description is not fuzzy');
        self::assertStringContainsString(
            'INSERT INTO "public"."fuzzphony_products" AS s ("id", "tsv", "fz", "exact", "t_name", "z_name", "t_brand", "z_brand", "t_description", "boost", "recency_at", "f_price", "f_in_stock", "f_published_at", "f_brand_id", "indexed_at")',
            $sql,
        );
        self::assertStringContainsString(
            "setweight(to_tsvector('\"public\".\"fuzzphony_english\"'::regconfig, coalesce(doc.\"fld_brand\"::text, '')), 'B'),\n        coalesce(\"public\".\"fuzzphony_norm\"(doc.\"fld_brand\"::text), ''),\n        setweight(to_tsvector('\"public\".\"fuzzphony_english\"'::regconfig, coalesce(doc.\"fld_description\"::text, '')), 'D'),\n        doc.fz_boost::double precision",
            $sql,
            'each field its own weighted vector (the same as its part of tsv) and, when fuzzy, its normalised text',
        );
        self::assertStringContainsString('"t_brand" = EXCLUDED."t_brand"', $sql);
        self::assertStringContainsString('"z_brand" = EXCLUDED."z_brand"', $sql);
    }

    public function testIndexesAreBuiltConcurrentlyOutsideTransactions(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $concurrent = array_values(array_filter($statements, static fn(Statement $s): bool => str_contains($s->sql, 'CONCURRENTLY')));

        self::assertCount(6, $concurrent); // tsv, trigram, 4 filters
        foreach ($concurrent as $statement) {
            self::assertFalse($statement->transactional);
        }
        self::assertStringContainsString('USING gin (fz "public".gin_trgm_ops)', $concurrent[1]->sql);
    }

    public function testQueueModeEnqueuesAndTriggerModeRefreshes(): void
    {
        $queue = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();
        $trigger = (new PostgresSchemaGenerator())->index(Indexes::products('trigger'))->toSql();
        $manual = (new PostgresSchemaGenerator())->index(Indexes::products('manual'))->toSql();

        self::assertStringContainsString('INSERT INTO "public"."fuzzphony_queue"', $queue);
        self::assertStringContainsString('PERFORM "public"."fuzzphony_refresh_products"', $trigger);
        self::assertStringNotContainsString('CREATE OR REPLACE TRIGGER', $manual);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand" ON "fz_brand"', $manual);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_upd" ON "fz_brand"', $manual);
    }

    public function testStatementLevelTriggersUseTransitionTables(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();

        self::assertStringContainsString('FROM fz_new AS r CROSS JOIN LATERAL (SELECT id FROM fz_product WHERE brand_id = r."id")', $sql);
        self::assertStringContainsString('AFTER UPDATE ON "fz_brand" REFERENCING OLD TABLE AS fz_old NEW TABLE AS fz_new FOR EACH STATEMENT', $sql);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand" ON "fz_brand"', $sql, 'row-level trigger of a previous setup is removed');
    }

    public function testRowLevelTriggers(): void
    {
        $definition = Indexes::products('queue')->withTriggerLevel(TriggerLevel::Row);
        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString('SELECT id FROM fz_product WHERE brand_id = NEW."id"', $sql);
        self::assertStringContainsString('SELECT id FROM fz_product WHERE brand_id = OLD."id"', $sql);
        self::assertStringContainsString('AFTER INSERT OR UPDATE OR DELETE ON "fz_brand" FOR EACH ROW', $sql);
        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_ins"', $sql);
    }

    public function testBothTriggerLevelsGetAStatementLevelTruncateTrigger(): void
    {
        foreach ([TriggerLevel::Statement, TriggerLevel::Row] as $level) {
            foreach (['queue', 'trigger'] as $sync) {
                $sql = (new PostgresSchemaGenerator())->index(Indexes::products($sync)->withTriggerLevel($level))->toSql();

                foreach (['fz_product', 'fz_brand'] as $table) {
                    self::assertStringContainsString(
                        sprintf('CREATE OR REPLACE TRIGGER "fuzzphony_sync_products__%1$s_trn" AFTER TRUNCATE ON "%1$s" FOR EACH STATEMENT EXECUTE FUNCTION "public"."fuzzphony_sync_products__%1$s"()', $table),
                        $sql,
                        sprintf('%s sync, %s level', $sync, $level->value),
                    );
                }
            }
        }
    }

    public function testSyncModesWithoutTriggersGetNoTruncateTrigger(): void
    {
        foreach (['manual', 'orm'] as $sync) {
            $sql = (new PostgresSchemaGenerator())->index(Indexes::products($sync))->toSql();

            self::assertStringNotContainsString('AFTER TRUNCATE', $sql);
            self::assertStringNotContainsString("TG_OP = 'TRUNCATE'", $sql);
            self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_trn" ON "fz_brand"', $sql, 'a truncate trigger of a previous setup is removed');
        }
    }

    public function testTruncateBranchComesFirstAndNeverTouchesRowsOrTransitionTables(): void
    {
        $filtered = IndexDefinition::builder('products')
            ->fromTable('fz_product')
            ->field('name', 'A')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->build();
        foreach ([Indexes::products('queue'), Indexes::products('trigger'), $filtered] as $definition) {
            foreach ([TriggerLevel::Statement, TriggerLevel::Row] as $level) {
                $definition = $definition->withTriggerLevel($level);
                foreach ((new PostgresSchemaGenerator())->index($definition)->statements as $statement) {
                    if (!str_contains($statement->sql, 'RETURNS trigger') || str_contains($statement->sql, 'fuzzphony_track_')) {
                        continue;
                    }
                    self::assertSame(1, preg_match("/\\ABEGIN\n    IF TG_OP = 'TRUNCATE' THEN\n(.*?)\n    END IF;/ms", substr($statement->sql, (int) strpos($statement->sql, "BEGIN\n")), $branch), $statement->sql);
                    self::assertStringEndsWith('RETURN NULL;', $branch[1] ?? '');
                    self::assertDoesNotMatchRegularExpression('/\b(NEW|OLD|fz_new|fz_old)\b/', $branch[1] ?? '');
                }
            }
        }
    }

    public function testTruncatingATableSourceEmptiesTheIndexOnlyWhenTheSourceIsReallyEmpty(): void
    {
        $definition = IndexDefinition::builder('articles')->fromTable('article')->field('title')->build();
        $guard = "IF TG_OP = 'TRUNCATE' THEN
        IF NOT EXISTS (SELECT 1 FROM \"article\") THEN
            DELETE FROM \"public\".\"fuzzphony_articles\";";
        $resync = "
        ELSE
            %s";

        $queue = (new PostgresSchemaGenerator())->index($definition)->toSql();
        self::assertStringContainsString(
            $guard . "
            IF to_regclass('\"public\".\"fuzzphony_articles__changes\"') IS NULL THEN DELETE FROM \"public\".\"fuzzphony_queue\" WHERE ctid IN (SELECT ctid FROM \"public\".\"fuzzphony_queue\" WHERE index_name = 'articles' FOR UPDATE SKIP LOCKED); END IF;"
            . sprintf($resync, "INSERT INTO \"public\".\"fuzzphony_queue\" (index_name, doc_id) VALUES ('articles', '*')\n            ON CONFLICT (index_name, doc_id) DO NOTHING;"),
            $queue,
        );
        self::assertStringNotContainsString("DELETE FROM \"public\".\"fuzzphony_queue\" WHERE index_name = 'articles'", $queue, 'never waits on the rows a worker holds');

        $trigger = (new PostgresSchemaGenerator())->index($definition->withSync(SyncMode::Trigger))->toSql();
        self::assertStringContainsString($guard . sprintf($resync, 'PERFORM "public"."fuzzphony_refresh_articles"(ARRAY(SELECT s.id FROM "public"."fuzzphony_articles" AS s UNION'), $trigger);
        self::assertStringNotContainsString('"public"."fuzzphony_queue" WHERE ctid', $trigger);
    }

    public function testASecondWatchedTableOfATableSourceNeverWipesTheIndex(): void
    {
        $definition = IndexDefinition::builder('articles')->fromTable('article')->field('title')->watch('comment', 'SELECT article_id FROM comment WHERE id = :id')->build();
        foreach (['queue', 'trigger'] as $sync) {
            $functions = array_values(array_filter(
                (new PostgresSchemaGenerator())->index($definition->withSync(SyncMode::from($sync)))->statements,
                static fn($statement): bool => str_contains($statement->sql, 'CREATE OR REPLACE FUNCTION "public"."fuzzphony_sync_articles__comment"'),
            ));
            self::assertCount(1, $functions);
            self::assertStringNotContainsString('DELETE FROM "public"."fuzzphony_articles";', $functions[0]->sql, $sync);
            self::assertStringNotContainsString('NOT EXISTS (SELECT 1 FROM "article")', $functions[0]->sql, $sync);
            self::assertStringContainsString("IF TG_OP = 'TRUNCATE' THEN
        " . ($sync === 'queue' ? 'INSERT INTO "public"."fuzzphony_queue"' : 'PERFORM "public"."fuzzphony_refresh_articles"'), $functions[0]->sql, $sync);
        }
    }

    public function testTruncatingAnotherWatchedTableResyncsEveryDocument(): void
    {
        $queue = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();
        self::assertStringContainsString(
            "IF TG_OP = 'TRUNCATE' THEN\n        INSERT INTO \"public\".\"fuzzphony_queue\" (index_name, doc_id) VALUES ('products', '*')\n        ON CONFLICT (index_name, doc_id) DO NOTHING;\n        RETURN NULL;\n    END IF;",
            $queue,
            'one rebuild job instead of every document id',
        );
        self::assertStringNotContainsString("SELECT 'products', t.id::text", $queue);
        self::assertStringNotContainsString('DELETE FROM "public"."fuzzphony_products";', $queue, 'a query source is never emptied wholesale');

        $trigger = (new PostgresSchemaGenerator())->index(Indexes::products('trigger'))->toSql();
        self::assertStringContainsString(
            "IF TG_OP = 'TRUNCATE' THEN\n        PERFORM \"public\".\"fuzzphony_refresh_products\"(ARRAY(SELECT s.id FROM \"public\".\"fuzzphony_products\" AS s UNION SELECT doc.fz_id::bigint FROM (SELECT d.\"id\" AS fz_id",
            $trigger,
        );
    }

    public function testRefreshFunctionUpsertsAndDeletesMissingDocuments(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('SELECT DISTINCT ON (doc.fz_id)', $sql);
        self::assertStringContainsString("setweight(to_tsvector('\"public\".\"fuzzphony_english\"'::regconfig, coalesce(doc.\"fld_name\"::text, '')), 'A')", $sql);
        self::assertStringContainsString('ON CONFLICT (id) DO UPDATE SET', $sql);
        self::assertStringContainsString('AND NOT EXISTS (SELECT 1 FROM', $sql);
    }

    public function testTheNormaliserQuotesAMixedCaseExtensionSchema(): void
    {
        $sql = (new PostgresSchemaGenerator(new Names('Ext')))->global(Indexes::products())->toSql();

        self::assertStringContainsString("SELECT btrim(regexp_replace(lower(\"Ext\".unaccent('\"Ext\".unaccent'::regdictionary, \$1)), '[^[:alnum:]]+', ' ', 'g'))", $sql);
    }

    public function testGlobalSchemaCreatesOneTextConfigPerLanguage(): void
    {
        $german = IndexDefinition::builder('articles')->fromTable('article')->field('title')->language('german')->build();
        $sql = (new PostgresSchemaGenerator())->global(Indexes::products(), Indexes::products(), $german)->toSql();

        self::assertSame(1, substr_count($sql, 'CREATE TEXT SEARCH CONFIGURATION "public"."fuzzphony_english"'));
        self::assertStringContainsString('WITH "public".unaccent, "german_stem"', $sql, 'for a language without a stop-word list');
        self::assertStringContainsString('WITH "public"."fuzzphony_german_stop", "public".unaccent, "german_stem"', $sql, 'accented stop words are dropped before unaccent');
        self::assertStringContainsString("FROM pg_ts_dict WHERE oid = '\"german_stem\"'::regdictionary", $sql, 'the stop-word list comes from the catalog');
        self::assertStringContainsString("EXECUTE format('CREATE TEXT SEARCH DICTIONARY %I.%I (TEMPLATE = pg_catalog.simple, STOPWORDS = %L, ACCEPT = false)', 'public', 'fuzzphony_german_stop', v_stopwords)", $sql);
        self::assertMatchesRegularExpression('/COPY = "english"\);\s+END IF;\s+SELECT substring/', $sql, 'only the CREATE is conditional, so --apply repairs an existing configuration');
    }

    public function testLongNamesStayWithinPostgresLimits(): void
    {
        $name = str_repeat('x', 48);
        $definition = IndexDefinition::builder($name)->fromTable('a_really_long_table_name_for_testing')->field('title')->build();
        $generator = new PostgresSchemaGenerator();

        self::assertLessThanOrEqual(63, strlen((new Names())->syncFunctionName($definition, $definition->effectiveWatches()[0])));
        foreach (array_keys($generator->indexes($definition)) as $index) {
            self::assertLessThanOrEqual(63, strlen($index));
        }
    }

    public function testDropNeverTouchesTheSource(): void
    {
        $sql = (new PostgresSchemaGenerator())->drop(Indexes::products())->toSql();

        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_products"', $sql);
        self::assertStringNotContainsString('DROP TABLE IF EXISTS "fz_product"', $sql);
    }

    public function testDropRemovesEveryTriggerAndFunctionAndForgetsQueuedItems(): void
    {
        $sql = (new PostgresSchemaGenerator())->drop(Indexes::products())->toSql();

        self::assertStringContainsString('DROP TRIGGER IF EXISTS "fuzzphony_sync_products__fz_brand_trn" ON "fz_brand"', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_sync_products__fz_brand"()', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_refresh_products"(bigint[])', $sql);
        self::assertStringContainsString("IF to_regclass('\"public\".\"fuzzphony_queue\"') IS NOT NULL THEN DELETE FROM \"public\".\"fuzzphony_queue\" WHERE index_name = 'products'; END IF;", $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_products__next"', $sql);
        self::assertStringContainsString('DROP TABLE IF EXISTS "public"."fuzzphony_products__changes"', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_refresh_products__next"(bigint[])', $sql);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS "public"."fuzzphony_track_products"()', $sql);
    }

    public function testTheRebuildHasItsOwnRefreshFunctionAndAChangeLogFunction(): void
    {
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products())->toSql();

        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "public"."fuzzphony_refresh_products__next"(p_ids bigint[]) RETURNS integer', $sql);
        self::assertStringContainsString('INSERT INTO "public"."fuzzphony_products__next" AS s ("id", "tsv"', $sql);
        self::assertStringContainsString('DELETE FROM "public"."fuzzphony_products__next" AS s', $sql);
        // the live refresh function logs every id it is given while a rebuild runs, after its own writes
        // (it takes the live table's lock first, like the swap): a document the live index never had
        // writes nothing there, so the change log trigger alone would miss it
        self::assertStringContainsString(<<<'SQL'
                  AND NOT EXISTS (SELECT 1 FROM (%s) AS doc WHERE doc.fz_id = s.id);

                IF to_regclass('"public"."fuzzphony_products__changes"') IS NOT NULL THEN
                    INSERT INTO "public"."fuzzphony_products__changes" (id) SELECT DISTINCT u.id FROM unnest(p_ids) AS u(id) WHERE u.id IS NOT NULL ORDER BY u.id ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
                END IF;

                RETURN written;
            SQL, str_replace(DocumentSql::select(Indexes::products()), '%s', $sql)); // the document query, twice, as %s
        self::assertSame(1, substr_count($sql, 'FROM unnest(p_ids)'), 'the rebuild refresh function logs nothing');
        self::assertStringContainsString(<<<'SQL'
            CREATE OR REPLACE FUNCTION "public"."fuzzphony_track_products"() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS $fuzzphony$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    INSERT INTO "public"."fuzzphony_products__changes" (id) VALUES (OLD.id) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
                ELSE
                    INSERT INTO "public"."fuzzphony_products__changes" (id) VALUES (NEW.id) ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id;
                END IF;
                RETURN NULL;
            END
            $fuzzphony$
            SQL, $sql);
        // a conflict locks the logged row (DO NOTHING would not): a catch-up batch cannot take an id
        // whose change is not committed yet
        self::assertStringNotContainsString('fuzzphony_products__changes" (id) VALUES (NEW.id) ON CONFLICT DO NOTHING', $sql);
    }

    public function testBeginRebuildStartsAnEmptyRebuildAndLogsTheLiveTable(): void
    {
        $sql = (new PostgresSchemaGenerator())->beginRebuild(Indexes::products());

        self::assertStringStartsWith(
            "DO \$fuzzphony\$\nDECLARE\n    r record;\nBEGIN\n    LOCK TABLE \"public\".\"fuzzphony_products\" IN SHARE ROW EXCLUSIVE MODE;\n    DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__next\";\n    DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__changes\";\n    CREATE TABLE \"public\".\"fuzzphony_products__changes\" (id bigint PRIMARY KEY);\n",
            $sql,
        );
        // the live table first, then the log: the order writers take them in (dropping a leftover log first could deadlock)
        // every role that may write the live table may write the log (its trigger runs as the writer; ON CONFLICT DO UPDATE needs SELECT and UPDATE)
        self::assertStringContainsString("FROM pg_class AS c, aclexplode(coalesce(c.relacl, acldefault('r', c.relowner))) AS a", $sql);
        self::assertStringContainsString("WHERE c.oid = '\"public\".\"fuzzphony_products\"'::regclass AND a.privilege_type IN ('INSERT', 'UPDATE', 'DELETE') LOOP", $sql);
        self::assertStringContainsString("EXECUTE format('GRANT SELECT, INSERT, UPDATE ON %s TO %s', '\"public\".\"fuzzphony_products__changes\"', CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END);", $sql);
        self::assertStringContainsString("CREATE TABLE \"public\".\"fuzzphony_products__next\" (\n    \"id\" bigint NOT NULL,\n    \"tsv\" tsvector NOT NULL,\n", $sql);
        self::assertStringContainsString("    \"indexed_at\" timestamptz NOT NULL DEFAULT now(),\n    CONSTRAINT \"fuzzphony_products_pkey__next\" PRIMARY KEY (\"id\")\n)", $sql);
        self::assertStringEndsWith(
            "    CREATE OR REPLACE TRIGGER \"fuzzphony_track_products\" AFTER INSERT OR UPDATE OR DELETE ON \"public\".\"fuzzphony_products\" FOR EACH ROW EXECUTE FUNCTION \"public\".\"fuzzphony_track_products\"();\nEND\n\$fuzzphony\$",
            $sql,
        );
    }

    public function testTheRebuildGetsItsIndexesAfterTheLoadUnderTemporaryNames(): void
    {
        $statements = (new PostgresSchemaGenerator())->shadowIndexes(Indexes::products());

        self::assertCount(7, $statements); // tsv, trigram, 4 filters, ANALYZE
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_tsv__next" ON "public"."fuzzphony_products__next" USING gin (tsv)', $statements[0]);
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_fz__next" ON "public"."fuzzphony_products__next" USING gin (fz "public".gin_trgm_ops)', $statements[1]);
        self::assertSame('CREATE INDEX IF NOT EXISTS "fuzzphony_products_f_brand_id__next" ON "public"."fuzzphony_products__next" ("f_brand_id")', $statements[5]);
        self::assertSame('ANALYZE "public"."fuzzphony_products__next"', $statements[6]);
    }

    public function testTheSwapKeepsGrantsAndOwnerAndRestoresTheLiveNames(): void
    {
        $sql = (new PostgresSchemaGenerator())->swap(Indexes::products());

        self::assertStringContainsString("WHERE c.oid = '\"public\".\"fuzzphony_products\"'::regclass AND a.grantee <> c.relowner LOOP", $sql);
        self::assertStringContainsString("EXECUTE format('GRANT %s ON %s TO %s%s', r.privilege_type, '\"public\".\"fuzzphony_products__next\"', r.grantee, CASE WHEN r.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);", $sql);
        self::assertStringContainsString("IF v_owner <> quote_ident(current_user) THEN\n        EXECUTE format('ALTER TABLE %s OWNER TO %s', '\"public\".\"fuzzphony_products__next\"', v_owner);", $sql);
        self::assertStringEndsWith(<<<'SQL'
                DROP TABLE "public"."fuzzphony_products";
                ALTER TABLE "public"."fuzzphony_products__next" RENAME TO "fuzzphony_products";
                ALTER TABLE "public"."fuzzphony_products" RENAME CONSTRAINT "fuzzphony_products_pkey__next" TO "fuzzphony_products_pkey";
                ALTER INDEX "public"."fuzzphony_products_tsv__next" RENAME TO "fuzzphony_products_tsv";
                ALTER INDEX "public"."fuzzphony_products_fz__next" RENAME TO "fuzzphony_products_fz";
                ALTER INDEX "public"."fuzzphony_products_f_price__next" RENAME TO "fuzzphony_products_f_price";
                ALTER INDEX "public"."fuzzphony_products_f_in_stock__next" RENAME TO "fuzzphony_products_f_in_stock";
                ALTER INDEX "public"."fuzzphony_products_f_published_at__next" RENAME TO "fuzzphony_products_f_published_at";
                ALTER INDEX "public"."fuzzphony_products_f_brand_id__next" RENAME TO "fuzzphony_products_f_brand_id";
                DROP TABLE "public"."fuzzphony_products__changes";
            END
            $fuzzphony$
            SQL, $sql);
    }

    public function testDiscardingARebuildRemovesItsTablesAndTheTrigger(): void
    {
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_products\"') IS NOT NULL THEN DROP TRIGGER IF EXISTS \"fuzzphony_track_products\" ON \"public\".\"fuzzphony_products\"; END IF; DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__next\"; DROP TABLE IF EXISTS \"public\".\"fuzzphony_products__changes\"; END \$fuzzphony\$",
            (new PostgresSchemaGenerator())->discardRebuild(Indexes::products()),
        );
    }

    public function testAFullRebuildTakesThePendingRequestWhenAllowedTo(): void
    {
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_queue\"') IS NOT NULL THEN IF has_table_privilege('\"public\".\"fuzzphony_queue\"', 'SELECT') AND has_table_privilege('\"public\".\"fuzzphony_queue\"', 'DELETE') THEN DELETE FROM \"public\".\"fuzzphony_queue\" WHERE index_name = 'products' AND doc_id = '*'; END IF; END IF; END \$fuzzphony\$",
            (new PostgresSchemaGenerator())->clearRebuildRequest(Indexes::products()),
        );
    }

    public function testTheIndexPlanFirstRefusesToRunWhileARebuildHoldsItsLock(): void
    {
        $first = (new PostgresSchemaGenerator(new Names('public', 'fz')))->index(Indexes::products())->statements[0];

        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF NOT pg_try_advisory_xact_lock(hashtext('fuzzphony:fz.products')) THEN RAISE EXCEPTION USING MESSAGE = 'A rebuild of \"products\" is running (fuzzphony:reindex): apply the schema again when it has finished, a new layout would break it.'; END IF; END \$fuzzphony\$",
            $first->sql,
        );
        self::assertSame('Refuse while a full reindex of "products" runs', $first->description);
        self::assertTrue($first->transactional, 'it holds the lock for the apply transaction');

        $drop = (new PostgresSchemaGenerator(new Names('public', 'fz')))->drop(Indexes::products())->statements[0];
        self::assertSame($first->sql, $drop->sql, '--drop --apply would remove what the rebuild writes');
        self::assertSame($first->description, $drop->description);
        self::assertTrue($drop->transactional);
    }

    public function testSecondaryIndexAndTriggerNames(): void
    {
        $generator = new PostgresSchemaGenerator();
        $index = Indexes::products();

        self::assertSame(
            ['fuzzphony_products_tsv', 'fuzzphony_products_fz', 'fuzzphony_products_f_price', 'fuzzphony_products_f_in_stock', 'fuzzphony_products_f_published_at', 'fuzzphony_products_f_brand_id'],
            array_keys($generator->indexes($index)),
        );
        self::assertSame(
            ['fuzzphony_sync_products__fz_brand', 'fuzzphony_sync_products__fz_brand_ins', 'fuzzphony_sync_products__fz_brand_upd', 'fuzzphony_sync_products__fz_brand_del', 'fuzzphony_sync_products__fz_brand_trn'],
            $generator->allTriggerNames($index, new Watch('fz_brand')),
        );
    }

    public function testRelevantColumnsAutoDerivesForTheSelfWatchOnATableSource(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')
            ->field('name', 'A')->filter('price', 'int')->boostBy('popularity')->recencyBy('created_at')
            ->build();
        $selfWatch = $definition->effectiveWatches()[0];

        self::assertSame(['name', 'price', 'popularity', 'created_at'], (new PostgresSchemaGenerator())->relevantColumns($definition, $selfWatch));
    }

    public function testRelevantColumnsIsNullForAQuerySourcesOwnWatch(): void
    {
        // Indexes::products() is a query source; its watch($table) targets the same physical
        // table the query reads from, but Fuzzphony has no certain column mapping for a query
        // source, so this must NOT be treated as an auto-derivable self-watch.
        $definition = Indexes::products();
        $ownWatch = $definition->watches[0];

        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $ownWatch));
    }

    public function testRelevantColumnsReturnsExplicitJoinedWatchColumns(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')->field('name', 'A')
            ->watch('brand', 'SELECT id FROM t WHERE brand_id = :id', columns: ['name', 'country'])
            ->build();
        $joinedWatch = $definition->watches[0];

        self::assertSame(['name', 'country'], (new PostgresSchemaGenerator())->relevantColumns($definition, $joinedWatch));
    }

    public function testRelevantColumnsIsNullForAJoinedWatchWithNoExplicitColumns(): void
    {
        $definition = Indexes::products();
        $brandWatch = $definition->watches[1]; // fz_brand, the joined watch

        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $brandWatch));
    }

    public function testRelevantColumnsTreatsAnExplicitEmptyListAsNull(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')->field('name', 'A')
            ->watch('brand', 'SELECT id FROM t WHERE brand_id = :id', columns: [])
            ->build();
        $joinedWatch = $definition->watches[0];

        // IndexBuilder::watch() normalizes [] to null at construction time, so Watch::$columns
        // itself is never a distinguishable-but-equivalent [] downstream (exporters, validator, ...).
        self::assertNull($joinedWatch->columns);
        self::assertNull((new PostgresSchemaGenerator())->relevantColumns($definition, $joinedWatch));
    }

    public function testRowLevelTriggersSkipUnchangedColumns(): void
    {
        $definition = Indexes::products('queue')->withTriggerLevel(TriggerLevel::Row)->withTenant(null);
        // Force a self-watch scenario isn't available on this query-sourced fixture; instead prove
        // the guard is emitted for a watch that DOES have relevant columns via an explicit list.
        $definition = IndexDefinition::builder($definition->name)
            ->fromQuery('SELECT p.id, p.name FROM fz_product p')
            ->field('name', 'A')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->triggerLevel(TriggerLevel::Row)
            ->build();

        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString(
            "IF TG_OP = 'UPDATE' AND NOT (NEW.\"name\" IS DISTINCT FROM OLD.\"name\" OR NEW.\"id\" IS DISTINCT FROM OLD.\"id\") THEN\n        RETURN NULL;\n    END IF;",
            $sql,
        );
    }

    /**
     * Regression guard: the row-level guard must always treat the correlating key column as
     * relevant, even when it isn't itself a field/filter/boost/recency column. Otherwise an
     * UPDATE that only changes the key column (e.g. renumbering a primary key) is wrongly
     * suppressed by the guard, leaving the old document stale and the new one never indexed.
     */
    public function testRowLevelGuardAlwaysIncludesTheKeyColumnEvenWhenNotOtherwiseRelevant(): void
    {
        $definition = IndexDefinition::builder('t')->fromTable('t')
            ->field('name', 'A')
            ->triggerLevel(TriggerLevel::Row)
            ->build();
        // The self-watch's key column defaults to "id", which is not itself a field/filter/
        // boost/recency column here, so relevantColumns() alone would omit it.
        self::assertSame(['name'], (new PostgresSchemaGenerator())->relevantColumns($definition, $definition->effectiveWatches()[0]));

        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString(
            "IF TG_OP = 'UPDATE' AND NOT (NEW.\"name\" IS DISTINCT FROM OLD.\"name\" OR NEW.\"id\" IS DISTINCT FROM OLD.\"id\") THEN\n        RETURN NULL;\n    END IF;",
            $sql,
        );
    }

    public function testRowLevelTriggersWithoutColumnsAreUnchanged(): void
    {
        $definition = Indexes::products('queue')->withTriggerLevel(TriggerLevel::Row);
        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringNotContainsString("TG_OP = 'UPDATE' AND NOT", $sql);
    }

    public function testStatementLevelTriggersWithoutColumnsAreByteIdenticalToBefore(): void
    {
        // Regression guard: relevantColumns() is null for every watch on Indexes::products()
        // (query source; no watch has explicit columns), so this must produce exactly what
        // testStatementLevelTriggersUseTransitionTables already asserts.
        $sql = (new PostgresSchemaGenerator())->index(Indexes::products('queue'))->toSql();

        self::assertStringContainsString('FROM fz_new AS r CROSS JOIN LATERAL (SELECT id FROM fz_product WHERE brand_id = r."id")', $sql);
        self::assertStringNotContainsString('LEFT JOIN', $sql);
    }

    public function testStatementLevelTriggersFilterByColumnOnUpdateOnly(): void
    {
        $definition = IndexDefinition::builder('products')
            ->fromQuery('SELECT p.id, p.name, b.name AS brand FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id', columns: ['name'])
            ->field('name', 'A')
            ->field('brand', 'B')
            ->build();

        $sql = (new PostgresSchemaGenerator())->index($definition)->toSql();

        self::assertStringContainsString("IF TG_OP = 'INSERT' THEN", $sql);
        self::assertStringContainsString("IF TG_OP = 'DELETE' THEN", $sql);
        self::assertStringContainsString("IF TG_OP = 'UPDATE' THEN", $sql);
        self::assertStringContainsString('fz_new AS r LEFT JOIN fz_old o ON o."id" = r."id"', $sql);
        self::assertStringContainsString('o."id" IS NULL OR (r."name" IS DISTINCT FROM o."name")', $sql);
        self::assertStringContainsString('fz_old AS r LEFT JOIN fz_new n ON n."id" = r."id"', $sql);
        self::assertStringContainsString('n."id" IS NULL OR (r."name" IS DISTINCT FROM n."name")', $sql);
        // INSERT/DELETE branches never reference the other side's transition table.
        self::assertStringNotContainsString("IF TG_OP = 'INSERT' THEN\n        PERFORM", $sql); // sanity: this fixture uses queue mode

        // Pin the critical property directly, by string, rather than relying on manual code
        // trace: the INSERT-only branch must never mention fz_old (not registered as a
        // transition table during an _ins-only firing), and the DELETE-only branch must never
        // mention fz_new (not registered during a _del-only firing).
        $insertMatched = preg_match("/IF TG_OP = 'INSERT' THEN(.*?)END IF;/s", $sql, $insertBranch);
        $deleteMatched = preg_match("/IF TG_OP = 'DELETE' THEN(.*?)END IF;/s", $sql, $deleteBranch);
        self::assertSame(1, $insertMatched);
        self::assertSame(1, $deleteMatched);
        self::assertStringNotContainsString('fz_old', $insertBranch[1] ?? '');
        self::assertStringNotContainsString('fz_new', $deleteBranch[1] ?? '');
    }

    public function testTheDefaultSchemaNeedsNoCreateSchema(): void
    {
        // PostgreSQL checks CREATE on the database before IF NOT EXISTS: a 0.3 install must still apply as a role without it.
        self::assertStringNotContainsString('CREATE SCHEMA', (new PostgresSchemaGenerator())->global(Indexes::products())->toSql());
    }

    public function testADedicatedSchemaIsCreatedFirstAndHoldsEveryObject(): void
    {
        $generator = new PostgresSchemaGenerator(new Names(schema: 'fuzzphony_s'));
        $global = $generator->global(Indexes::products());
        $sql = $global->merge($generator->index(Indexes::products()))->toSql();

        self::assertSame('CREATE SCHEMA IF NOT EXISTS "fuzzphony_s"', $global->statements[0]->sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "fuzzphony_s"."fuzzphony_queue"', $sql);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS fuzzphony_queue_order ON "fuzzphony_s"."fuzzphony_queue"', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "fuzzphony_s"."fuzzphony_products"', $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "fuzzphony_s"."fuzzphony_norm"(text)', $sql);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION "fuzzphony_s"."fuzzphony_refresh_products"(p_ids bigint[])', $sql);
        self::assertStringContainsString('EXECUTE FUNCTION "fuzzphony_s"."fuzzphony_sync_products__fz_brand"()', $sql);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS "fuzzphony_products_tsv" ON "fuzzphony_s"."fuzzphony_products"', $sql);
        self::assertStringContainsString('INSERT INTO "fuzzphony_s"."fuzzphony_queue" (index_name, doc_id)', $sql);
        self::assertStringContainsString("setweight(to_tsvector('\"fuzzphony_s\".\"fuzzphony_english\"'::regconfig", $sql);
        self::assertStringContainsString('"fuzzphony_s"."fuzzphony_norm"(doc."fld_name"::text)', $sql);
    }

    public function testGeneratedFunctionsPinTheirSearchPath(): void
    {
        $generator = new PostgresSchemaGenerator();
        $sql = $generator->global(Indexes::products())->merge($generator->index(Indexes::products()))->toSql();

        self::assertStringContainsString("RETURNS text\nLANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT\nSET search_path = pg_catalog, pg_temp\nAS \$fuzzphony\$", $sql);
        self::assertStringContainsString("RETURNS integer\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$", $sql);
        self::assertSame(2, substr_count($sql, "RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'both sync functions');
        self::assertSame(2, substr_count($sql, "RETURNS integer\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'the live and the rebuild refresh function');
        self::assertStringContainsString("RETURNS trigger\nLANGUAGE plpgsql SET search_path = pg_catalog, pg_temp AS \$fuzzphony\$", $sql, 'the change log function embeds no developer SQL');

        $row = $generator->index(Indexes::products()->withTriggerLevel(TriggerLevel::Row))->toSql();
        self::assertSame(2, substr_count($row, "RETURNS trigger\nLANGUAGE plpgsql SET search_path FROM CURRENT AS \$fuzzphony\$"), 'row-level sync functions too');
    }

    public function testCatalogLookupsCompareTheExactSchemaName(): void
    {
        $sql = (new PostgresSchemaGenerator(new Names(schema: 'Fz')))->global(Indexes::products())->toSql();

        self::assertStringContainsString("WHERE c.cfgname = 'fuzzphony_english' AND n.nspname = 'Fz'", $sql);
        self::assertStringContainsString("WHERE d.dictname = 'fuzzphony_english_stop' AND n.nspname = 'Fz'", $sql);
        self::assertStringContainsString("EXECUTE format('CREATE TEXT SEARCH DICTIONARY %I.%I (TEMPLATE = pg_catalog.simple, STOPWORDS = %L, ACCEPT = false)', 'Fz', 'fuzzphony_english_stop', v_stopwords)", $sql);
        self::assertStringContainsString('CREATE TEXT SEARCH CONFIGURATION "Fz"."fuzzphony_english" (COPY = "english")', $sql);
        self::assertStringContainsString('WITH "Fz"."fuzzphony_english_stop", "public".unaccent, "english_stem"', $sql);
    }

    public function testApplyCreatesTheMetaTableAndRecordsTheSharedObjectsLast(): void
    {
        $statements = (new PostgresSchemaGenerator())->global(Indexes::products())->statements;
        $sql = implode("\n", array_map(static fn(Statement $s): string => $s->sql, $statements));
        $last = $statements[count($statements) - 1];

        self::assertStringContainsString(
            "CREATE TABLE IF NOT EXISTS \"public\".\"fuzzphony_meta\" (\n    index_name text PRIMARY KEY,\n    layout_version integer NOT NULL,\n    definition_hash text NOT NULL,\n    documents_hash text,\n    library_version text NOT NULL,\n    applied_at timestamptz NOT NULL,\n    reindexed_at timestamptz\n)",
            $sql,
        );
        self::assertFalse($last->transactional, 'after everything else');
        self::assertSame(self::upsert('*', Fingerprint::shared(new Names())), $last->sql);
    }

    public function testApplyRecordsTheIndexLayoutAndDefinitionAfterTheConcurrentIndexBuilds(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $last = $statements[count($statements) - 1];

        self::assertSame(2, PostgresSchemaGenerator::LAYOUT_VERSION);
        self::assertFalse($last->transactional);
        self::assertSame(self::upsert('products', Fingerprint::definition(Indexes::products())), $last->sql);
        self::assertSame('Record the layout and definition "products" was built from', $last->description);
    }

    public function testALayoutStepRunsOnlyForAnIndexStoredWithAnOlderLayout(): void
    {
        $statements = (new PostgresSchemaGenerator())->index(Indexes::products())->statements;
        $steps = array_values(array_filter($statements, static fn(Statement $s): bool => str_starts_with($s->description, 'Layout step')));

        self::assertCount(1, $steps);
        self::assertTrue($steps[0]->transactional, 'in the apply transaction, so before the non-transactional meta upsert');
        self::assertSame('Layout step to 2: the per-field columns of existing documents stay empty until a full reindex', $steps[0]->description);
        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN IF (SELECT layout_version FROM \"public\".\"fuzzphony_meta\" WHERE index_name = 'products') < 2 THEN UPDATE \"public\".\"fuzzphony_meta\" SET documents_hash = NULL WHERE index_name = 'products'; END IF; END IF; END \$fuzzphony\$",
            $steps[0]->sql,
        );
        self::assertSame($steps[0], $statements[count($statements) - 2], 'right before the meta upsert, also in --dump-migration');
    }

    public function testDropForgetsTheVersionRecordAndAReindexRecordsTheDocuments(): void
    {
        $generator = new PostgresSchemaGenerator();
        $drop = $generator->drop(Indexes::products())->statements;

        self::assertSame(
            "DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN DELETE FROM \"public\".\"fuzzphony_meta\" WHERE index_name = 'products'; END IF; END \$fuzzphony\$",
            $drop[count($drop) - 1]->sql,
        );
        self::assertSame(
            sprintf("DO \$fuzzphony\$ BEGIN IF to_regclass('\"public\".\"fuzzphony_meta\"') IS NOT NULL THEN UPDATE \"public\".\"fuzzphony_meta\" SET documents_hash = '%s', reindexed_at = now() WHERE index_name = 'products'; END IF; END \$fuzzphony\$", Fingerprint::documents(Indexes::products())),
            $generator->reindexed(Indexes::products()),
        );
    }

    private static function upsert(string $index, string $hash): string
    {
        return sprintf(
            "INSERT INTO \"public\".\"fuzzphony_meta\" (index_name, layout_version, definition_hash, library_version, applied_at)\nVALUES ('%s', 2, '%s', %s, now())\nON CONFLICT (index_name) DO UPDATE SET layout_version = EXCLUDED.layout_version, definition_hash = EXCLUDED.definition_hash, library_version = EXCLUDED.library_version, applied_at = EXCLUDED.applied_at",
            $index,
            $hash,
            Sql::string((string) InstalledVersions::getPrettyVersion('fuzzphony/fuzzphony')),
        );
    }
}
