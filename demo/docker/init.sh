#!/bin/sh
# One-shot bootstrap, safe to run on every `docker compose up`:
#   refuse default secrets on a published address, create / update the application role, seed the catalogue only
#   if it is missing, apply the schema (idempotent), index only when there is something to index, grant the
#   application role what it needs, then run the doctor. Exit code != 0 keeps the rest of the stack from starting.
#
# init itself connects as the database owner (PGUSER / DATABASE_URL), which has no statement_timeout, so seeding and
# reindexing are never cut short; php-fpm and the worker connect as the application role configured below.
set -eu

cd /app/demo
: "${DEMO_ROWS:=200000}"
: "${DEMO_REINDEX:=auto}"   # auto | always | never
log() { printf '[init] %s\n' "$*"; }
APP_ROLE=fuzzphony_app
APP_STATEMENT_TIMEOUT=5s

# The documented defaults are fine on 127.0.0.1, never on an address other machines can reach.
is_loopback() { case "$1" in 127.*|localhost|::1|'[::1]') return 0 ;; *) return 1 ;; esac; }
if ! is_loopback "${DEMO_BIND:-127.0.0.1}" && [ "${APP_SECRET:-}" = demo-only-not-a-secret-change-me ]; then
    log "refusing to start: the web port is published on ${DEMO_BIND}, but DEMO_APP_SECRET is still the documented default."
    log "set DEMO_APP_SECRET to a random value (e.g. openssl rand -hex 32), or keep DEMO_BIND=127.0.0.1"
    exit 1
fi
if ! is_loopback "${DEMO_DB_BIND:-127.0.0.1}" && [ "${PGPASSWORD:-}" = fuzzphony ]; then
    log "refusing to start: the database port is published on ${DEMO_DB_BIND}, but DEMO_DB_PASSWORD is still the documented default."
    log "set DEMO_DB_PASSWORD to a random value (letters, digits, - and _), or keep DEMO_DB_BIND=127.0.0.1"
    exit 1
fi

until pg_isready -q; do log "waiting for the database"; sleep 2; done

# The role php-fpm and the worker use: no superuser, no DDL, and every statement is cut off after
# $APP_STATEMENT_TIMEOUT, so no request can keep the database busy. Re-applied on every start.
log "configuring the application role $APP_ROLE (statement_timeout $APP_STATEMENT_TIMEOUT)"
psql -q -v ON_ERROR_STOP=1 -v role="$APP_ROLE" -v password="$PGPASSWORD" -v timeout="$APP_STATEMENT_TIMEOUT" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'role') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'role') \gexec
ALTER ROLE :"role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'password';
ALTER ROLE :"role" SET statement_timeout = :'timeout';
SQL

seeded=0
if [ "$(psql -tAc "SELECT to_regclass('bench_product') IS NOT NULL")" = t ]; then
    log "catalogue present, not seeding (docker compose down -v to start over)"
else
    log "seeding $DEMO_ROWS products (benchmarks/seed.sql)"
    # -1: one transaction, so an interrupted seed leaves nothing behind and the next start seeds again.
    # (no slow-statement logging or notices: the bulk INSERT would otherwise be dumped into the log)
    PGOPTIONS='-c log_min_duration_statement=-1 -c client_min_messages=warning' \
        psql -q -1 -v rows="$DEMO_ROWS" -f /app/benchmarks/seed.sql
    seeded=1
fi

log "applying the schema"
php bin/console fuzzphony:schema --apply --no-interaction

# Right after the schema was created the index table is empty; an existing one is kept in sync by the
# triggers + the worker, so a full reindex on every start would only cost time.
indexed=$(psql -tAc "SELECT EXISTS (SELECT 1 FROM fuzzphony_catalog)")
if [ "$DEMO_REINDEX" = always ] || { [ "$DEMO_REINDEX" = auto ] && { [ "$seeded" = 1 ] || [ "$indexed" != t ]; }; }; then
    log "reindexing"
    php bin/console fuzzphony:reindex --batch=20000 --no-interaction
else
    log "index present, not reindexing (DEMO_REINDEX=always forces it)"
fi

# Read access to the catalogue, write access only to Fuzzphony's own tables (the index and the sync queue).
log "granting $APP_ROLE access"
psql -q -v ON_ERROR_STOP=1 -v role="$APP_ROLE" <<'SQL'
GRANT USAGE ON SCHEMA public TO :"role";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO :"role";
SELECT format('GRANT INSERT, UPDATE, DELETE ON %I.%I TO %I', schemaname, tablename, :'role')
FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'fuzzphony\_%' \gexec
SQL

php bin/console fuzzphony:doctor --no-interaction
log "done"
