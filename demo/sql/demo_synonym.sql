-- The demo's synonyms, one row per index, as a list in the Solr format. The /synonyms page edits them and a
-- request listener hands them to Fuzzphony (Fuzzphony::useSynonyms), so changing them needs no reindex.
-- A real application keeps its own table (or a file) and does the same.
-- Seeded once by docker/init.sh (only while the table does not exist).

CREATE TABLE demo_synonym (
    index_name text PRIMARY KEY,
    body       text NOT NULL
);

INSERT INTO demo_synonym (index_name, body) VALUES
('catalog', $$# "mice" is an irregular plural no stemmer links to "mouse"; "drill => screwdriver" works one way only.
tv, television, telly
mouse, mice
headphones, headset, earphones
monitor, display, screen
drill => screwdriver
$$),
('lang_en', $$tv, television, telly
laptop, notebook
headphones, headset, earphones
mouse, mice
kettle, water boiler
drill => screwdriver
$$),
('lang_de', $$tv, fernseher, fernsehgerät
laptop, notebook
kopfhörer, headset
wasserkocher, teekocher
handy, smartphone, mobiltelefon
$$),
('lang_fr', $$tv, télévision, téléviseur, télé
écouteurs, casque audio
vélo, bicyclette
ordinateur portable, laptop
$$),
('lang_es', $$tv, televisor, televisión, tele
auriculares, cascos
bicicleta, bici
móvil, celular, teléfono
ratón, mouse
$$),
('lang_hu', $$tv, televízió, tévé
fejhallgató, fülhallgató
egér, mouse
kerékpár, bicikli
vízforraló, teafőző
$$);
