-- Synthetic catalogue for benchmarks and the demo.
--   psql "$DSN" -v rows=1000000 -f benchmarks/seed.sql
\set ON_ERROR_STOP on
\if :{?rows}
\else
  \set rows 1000000
\endif

DROP TABLE IF EXISTS bench_product, bench_brand, bench_category CASCADE;
CREATE TABLE bench_brand (id bigint PRIMARY KEY, name text NOT NULL);
CREATE TABLE bench_category (id bigint PRIMARY KEY, name text NOT NULL);
CREATE TABLE bench_product (
    id bigint PRIMARY KEY,
    name text NOT NULL,
    description text NOT NULL,
    brand_id bigint NOT NULL REFERENCES bench_brand,
    category_id bigint NOT NULL REFERENCES bench_category,
    price integer NOT NULL,
    in_stock boolean NOT NULL,
    popularity real NOT NULL,
    published_at timestamptz NOT NULL
);

INSERT INTO bench_brand
SELECT i, (ARRAY['Logitech','Razer','Sony','Bosch','Makita','Philips','Samsung','Lenovo','Canon','Tefal'])[1 + (i - 1) % 10] || ' ' || i
FROM generate_series(1, 500) AS i;

INSERT INTO bench_category
SELECT i, (ARRAY['Mice','Keyboards','Headphones','Drills','Kitchen','Monitors','Cameras','Lighting','Garden','Office'])[1 + (i - 1) % 10]
FROM generate_series(1, 50) AS i;

INSERT INTO bench_product
SELECT i,
       initcap(a.adj) || ' ' || n.noun || ' ' || (1000 + i % 9000),
       'A ' || a.adj || ' ' || n.noun || ' with ' || f.feature || ', ideal for ' || u.usage || '.',
       1 + (i * 7) % 500,
       -- category_idx picks one of the 10 category names *matching the noun* (see n below), then
       -- spreads across the 5 bench_category rows that share that name (ids 1..50, 10 names cycling
       -- every 10) instead of always the same row -- keeps category_id cardinality/selectivity close
       -- to the old, uncorrelated version while making name <-> category semantically coherent.
       1 + n.category_idx + 10 * ((i / 100) % 5),
       990 + (i * 37) % 200000,
       i % 7 <> 0,
       (i % 100) / 10.0,
       now() - make_interval(days => i % 1000)
FROM generate_series(1, :rows) AS i
CROSS JOIN LATERAL (SELECT (ARRAY['wireless','ergonomic','silent','compact','professional','crème','rugged','smart','vintage','ultralight'])[1 + i % 10] AS adj) a
CROSS JOIN LATERAL (SELECT
    (ARRAY['mouse','keyboard','headphones','drill','kettle','monitor','camera','lamp','mower','chair','café grinder','screwdriver'])[1 + (i / 10) % 12] AS noun,
    -- index into bench_category's name list (['Mice','Keyboards','Headphones','Drills','Kitchen',
    -- 'Monitors','Cameras','Lighting','Garden','Office']), aligned by position with the noun array above.
    (ARRAY[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 4, 3])[1 + (i / 10) % 12] AS category_idx
) n
CROSS JOIN LATERAL (SELECT (ARRAY['long battery life','noise cancelling','USB-C charging','brushless motor','aluminium body','RGB lighting'])[1 + (i / 7) % 6] AS feature) f
CROSS JOIN LATERAL (SELECT (ARRAY['the office','gaming','travel','the workshop','the kitchen','the garden'])[1 + (i / 3) % 6] AS usage) u;

ANALYZE bench_brand, bench_category, bench_product;
