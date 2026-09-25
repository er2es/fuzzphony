#!/bin/sh
# One-shot bootstrap, safe to run on every `docker compose up`:
#   seed the catalogue only if it is missing, apply the schema (idempotent), index only when there is something
#   to index, then run the doctor. Exit code != 0 keeps the rest of the stack from starting.
set -eu

cd /app/demo
: "${DEMO_ROWS:=200000}"
: "${DEMO_REINDEX:=auto}"   # auto | always | never
log() { printf '[init] %s\n' "$*"; }

until pg_isready -q; do log "waiting for the database"; sleep 2; done

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

php bin/console fuzzphony:doctor --no-interaction
log "done"
