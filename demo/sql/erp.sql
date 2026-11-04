-- The ERP-like data set of the demo: customers (persons and companies), their contacts and addresses, orders and order lines.
-- Plain application tables, read by the indexes people, companies, orders and site (config/packages/fuzzphony.yaml,
-- src/Search/PeopleAdminIndex.php). Hungarian-flavoured and fully deterministic (no random(): the same rows on every start).
-- Seeded once by docker/init.sh (only while erp_customer does not exist); run with `psql -v customers=100000` (the default;
-- DEMO_ERP=0 passes 0 and creates the empty tables, so the indexes stay valid). Per 100 000 customers: 20 000 companies with 3
-- contacts each (60 000), 150 000 addresses, 60 000 orders, 130 000 order lines (about 520 000 rows in all).
-- product_id of a line points at bench_product (no foreign key: the catalogue is seeded and reseeded on its own).

CREATE FUNCTION pg_temp.fold(t text) RETURNS text LANGUAGE sql IMMUTABLE AS
$$ SELECT translate(lower(t), 'áéíóöőúüű', 'aeiooouuu') $$;

CREATE TABLE erp_customer (
    id         bigint PRIMARY KEY,
    kind       text NOT NULL CHECK (kind IN ('person', 'company')),
    name       text NOT NULL,
    tax_no     text,
    birth_name text,
    email      text NOT NULL,
    created_at timestamptz NOT NULL
);
CREATE TABLE erp_contact (
    id          bigint PRIMARY KEY,
    customer_id bigint NOT NULL REFERENCES erp_customer,
    name        text NOT NULL,
    role        text NOT NULL,
    phone       text NOT NULL,
    email       text NOT NULL
);
CREATE TABLE erp_address (
    id          bigint PRIMARY KEY,
    customer_id bigint NOT NULL REFERENCES erp_customer,
    kind        text NOT NULL CHECK (kind IN ('billing', 'shipping')),
    zip         text NOT NULL,
    city        text NOT NULL,
    street      text NOT NULL
);
CREATE TABLE erp_order (
    id          bigint PRIMARY KEY,
    customer_id bigint NOT NULL REFERENCES erp_customer,
    number      text NOT NULL,
    status      text NOT NULL,
    total       integer NOT NULL DEFAULT 0,
    ordered_at  timestamptz NOT NULL
);
CREATE TABLE erp_order_line (
    id         bigint PRIMARY KEY,
    order_id   bigint NOT NULL REFERENCES erp_order,
    product_id bigint NOT NULL,
    qty        integer NOT NULL,
    price      integer NOT NULL
);

-- Customers: every fifth one is a company. Hungarian order of names (family name first); persons have a birth name
-- (the same as the name, or another family name for every third one).
INSERT INTO erp_customer
SELECT i,
       CASE WHEN i % 5 = 0 THEN 'company' ELSE 'person' END,
       CASE WHEN i % 5 = 0
            THEN (ARRAY['Duna','Tisza','Balaton','Mecsek','Mátra','Bakony','Pannon','Alföld','Dunántúli','Magyar','Budai','Pesti','Hajdú','Zemplén','Kárpát','Nova','Alfa','Delta','Omega','Prímusz'])[1 + i % 20]
                 || ' ' || (ARRAY['Szoftver','Építő','Kereskedelmi','Logisztikai','Pékség','Gépgyár','Élelmiszer','Fuvarozó','Tanácsadó','Mezőgazdasági','Nyomda','Informatikai','Autó','Bútor','Vegyipari','Textil','Energia','Építőanyag','Csomagoló','Elektronika'])[1 + (i / 20) % 20]
                 || ' ' || (ARRAY['Kft.','Kft.','Zrt.','Bt.','Kft.'])[1 + (i / 400) % 5]
            ELSE l.last || ' ' || f.first END,
       CASE WHEN i % 5 = 0 THEN lpad((10000000 + (i * 7919) % 89999999)::text, 8, '0') || '-' || (1 + i % 2) || '-' || lpad((2 + i % 43)::text, 2, '0') END,
       CASE WHEN i % 5 <> 0 THEN CASE WHEN i % 3 = 0 THEN b.last ELSE l.last END || ' ' || f.first END,
       pg_temp.fold(CASE WHEN i % 5 = 0 THEN 'info' ELSE f.first || '.' || l.last END) || i || '@' || CASE WHEN i % 5 = 0 THEN 'cegmail.example.hu' ELSE (ARRAY['example.hu','mail.example.hu','posta.example.hu'])[1 + i % 3] END,
       timestamptz '2020-01-01 08:00+00' + ((i * 977) % 2400) * interval '1 day' + (i % 600) * interval '1 minute'
FROM generate_series(1, :customers) AS i
CROSS JOIN LATERAL (SELECT (ARRAY['Nagy','Kovács','Tóth','Szabó','Horváth','Varga','Kiss','Molnár','Németh','Farkas','Balogh','Papp','Takács','Juhász','Lakatos','Mészáros','Oláh','Simon','Rácz','Fekete','Szilágyi','Török','Fehér','Balázs','Gál','Kis','Szűcs','Kocsis','Pintér','Fodor','Orosz','Szalai','Sipos','Magyar','Lukács','Gulyás','Biró','Király','Katona','László'])[1 + (i + i / 40) % 40] AS last) AS l
CROSS JOIN LATERAL (SELECT (ARRAY['Nagy','Kovács','Tóth','Szabó','Horváth','Varga','Kiss','Molnár','Németh','Farkas','Balogh','Papp','Takács','Juhász','Lakatos','Mészáros','Oláh','Simon','Rácz','Fekete','Szilágyi','Török','Fehér','Balázs','Gál','Kis','Szűcs','Kocsis','Pintér','Fodor','Orosz','Szalai','Sipos','Magyar','Lukács','Gulyás','Biró','Király','Katona','László'])[1 + (i * 7 + i / 11) % 40] AS last) AS b
CROSS JOIN LATERAL (SELECT (ARRAY['Anna','Eszter','Katalin','Zsuzsanna','Mária','Éva','Judit','Ilona','Ágnes','Erzsébet','Hajnalka','Réka','Orsolya','Nóra','Zsófia','Lilla','Petra','Dóra','Gabriella','Tímea','István','László','József','János','Zoltán','Gábor','Péter','Attila','Ferenc','Tamás','Csaba','Balázs','Ádám','Bence','Máté','Dávid','Levente','Miklós','Róbert','Sándor'])[1 + (i * 13 + i / 7) % 40] AS first) AS f;

-- One well-known tax number for the demo's examples (the generated ones are all different).
UPDATE erp_customer SET tax_no = '12345678-2-41' WHERE id = 5 AND :customers >= 5;

-- Contacts: three per company.
INSERT INTO erp_contact
SELECT (c.id / 5 - 1) * 3 + n, c.id, l.last || ' ' || f.first,
       (ARRAY['CEO','Accountant','Sales manager','Purchasing','Logistics'])[1 + (c.id / 5 + n) % 5],
       '+36 ' || (ARRAY[20,30,70,1,50])[1 + (c.id + n) % 5] || ' ' || lpad(((c.id * 31 + n * 7) % 900 + 100)::text, 3, '0') || ' ' || lpad(((c.id * 977 + n * 13) % 10000)::text, 4, '0'),
       pg_temp.fold(f.first || '.' || l.last) || '.' || ((c.id / 5 - 1) * 3 + n) || '@' || pg_temp.fold(split_part(c.name, ' ', 1)) || '.example.hu'
FROM erp_customer c
CROSS JOIN generate_series(1, 3) AS n
CROSS JOIN LATERAL (SELECT (ARRAY['Nagy','Kovács','Tóth','Szabó','Horváth','Varga','Kiss','Molnár','Németh','Farkas','Balogh','Papp','Takács','Juhász','Lakatos','Mészáros','Oláh','Simon','Rácz','Fekete','Szilágyi','Török','Fehér','Balázs','Gál','Kis','Szűcs','Kocsis','Pintér','Fodor','Orosz','Szalai','Sipos','Magyar','Lukács','Gulyás','Biró','Király','Katona','László'])[1 + (c.id * 3 + n * 11 + c.id / 9) % 40] AS last) AS l
CROSS JOIN LATERAL (SELECT (ARRAY['Anna','Eszter','Katalin','Zsuzsanna','Mária','Éva','Judit','Ilona','Ágnes','Erzsébet','Hajnalka','Réka','Orsolya','Nóra','Zsófia','Lilla','Petra','Dóra','Gabriella','Tímea','István','László','József','János','Zoltán','Gábor','Péter','Attila','Ferenc','Tamás','Csaba','Balázs','Ádám','Bence','Máté','Dávid','Levente','Miklós','Róbert','Sándor'])[1 + (c.id * 17 + n * 5 + c.id / 13) % 40] AS first) AS f
WHERE c.kind = 'company';

-- Addresses: one or two per customer (every second customer has two: the first billing, the second shipping), Budapest is common.
INSERT INTO erp_address
SELECT row_number() OVER (ORDER BY c.id, n),
       c.id, CASE WHEN n = 1 THEN 'billing' ELSE 'shipping' END,
       z.zip, z.city,
       (ARRAY['Kossuth Lajos','Petőfi Sándor','Ady Endre','Széchenyi','Arany János','Rákóczi','Bethlen Gábor','Dózsa György','Fő','Templom','Iskola','Kodály Zoltán','Bartók Béla','Vörösmarty','Jókai Mór','Deák Ferenc','Tompa Mihály','Szent István','Mátyás király','Wesselényi'])[1 + (c.id * 3 + n * 7) % 20]
           || ' ' || (ARRAY['utca','utca','utca','út','tér','körút'])[1 + (c.id + n) % 6] || ' ' || (1 + (c.id * 11 + n * 3) % 120)
FROM erp_customer c
CROSS JOIN LATERAL generate_series(1, CASE WHEN c.id % 2 = 0 THEN 2 ELSE 1 END) AS n
CROSS JOIN LATERAL (SELECT (ARRAY['Budapest','Debrecen','Szeged','Miskolc','Pécs','Győr','Nyíregyháza','Kecskemét','Székesfehérvár','Szombathely','Szolnok','Tatabánya','Érd','Veszprém','Békéscsaba','Zalaegerszeg','Sopron','Eger','Nagykanizsa','Dunaújváros','Budapest','Budapest','Budapest','Budapest'])[1 + (c.id * 5 + n * 3 + c.id / 17) % 24] AS city,
                           (ARRAY['1051','4024','6720','3525','7621','9021','4400','6000','8000','9700','5000','2800','2030','8200','5600','8900','9400','3300','8800','2400','1111','1135','1011','1138'])[1 + (c.id * 5 + n * 3 + c.id / 17) % 24] AS zip) AS z;

-- Orders: a customer's orders cluster on the first customers of each block; the number carries the year and the id.
INSERT INTO erp_order
SELECT i, 1 + (i * 37) % :customers,
       'ORD-' || extract(year FROM t.at)::int || '-' || lpad(i::text, 6, '0'),
       (ARRAY['new','paid','shipped','delivered','delivered','delivered','cancelled'])[1 + i % 7],
       0, t.at
FROM generate_series(1, (:customers * 6) / 10) AS i
CROSS JOIN LATERAL (SELECT timestamptz '2024-01-01 08:00+00' + ((i * 613) % 1000) * interval '1 day' + (i % 700) * interval '1 minute' AS at) AS t;

-- Lines: one to three per order (1,2,2,2,3,3 over six consecutive orders: 13 per 6), products of the catalogue.
INSERT INTO erp_order_line
SELECT row_number() OVER (ORDER BY o.id, n), o.id,
       1 + (o.id * 7919 + n * 104729) % GREATEST((SELECT max(id) FROM bench_product), 1),
       1 + (o.id + n) % 5,
       990 + ((o.id * 131 + n * 977) % 9000) * 10
FROM erp_order o
CROSS JOIN LATERAL generate_series(1, (ARRAY[1,2,2,2,3,3])[1 + o.id % 6]) AS n;

UPDATE erp_order o SET total = s.total FROM (SELECT order_id, sum(qty * price)::int AS total FROM erp_order_line GROUP BY order_id) s WHERE s.order_id = o.id;

-- Indexes the watch queries and the many-value lookups need (PostgreSQL does not create one for a foreign key).
CREATE INDEX erp_address_customer_idx ON erp_address (customer_id);
CREATE INDEX erp_contact_customer_idx ON erp_contact (customer_id);
CREATE INDEX erp_order_customer_idx ON erp_order (customer_id);
CREATE INDEX erp_order_line_order_idx ON erp_order_line (order_id);
CREATE INDEX erp_order_line_product_idx ON erp_order_line (product_id);
ANALYZE erp_customer, erp_contact, erp_address, erp_order, erp_order_line;
