-- The demo's synonyms, kept like an application with a long list would keep them: one row per entry (a line of the
-- Solr format) and a version per index, which every save of the /synonyms page bumps. A request reads the versions
-- and takes the prepared lists from a cache keyed by them (src/Service/SynonymStore.php), so changing a list needs
-- no reindex and a long one costs almost nothing per request.
-- Seeded once by docker/init.sh (only while demo_synonym_entry does not exist). A demo database from 0.7.1 had one
-- text row per index in demo_synonym: the lists are converted from it, and that table is left alone.

CREATE TABLE demo_synonym_entry (
    index_name text NOT NULL,
    position   integer NOT NULL,
    entry      text NOT NULL,
    PRIMARY KEY (index_name, position)
);
CREATE TABLE demo_synonym_version (
    index_name text PRIMARY KEY,
    version    integer NOT NULL
);

CREATE TEMP TABLE seed (index_name text, body text);
DO $do$
BEGIN
    IF to_regclass('public.demo_synonym') IS NOT NULL THEN
        EXECUTE 'INSERT INTO seed SELECT index_name, body FROM public.demo_synonym';
    ELSE
        INSERT INTO seed VALUES
        -- "mice" is an irregular plural no stemmer links to "mouse"; "drill => screwdriver" works one way only.
        ('catalog', $$tv, television, telly
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
    END IF;
END
$do$;

INSERT INTO demo_synonym_entry (index_name, position, entry)
SELECT s.index_name, l.position, btrim(l.line)
FROM seed s, unnest(string_to_array(s.body, E'\n')) WITH ORDINALITY AS l(line, position)
WHERE btrim(l.line) <> '' AND btrim(l.line) NOT LIKE '#%';

INSERT INTO demo_synonym_version (index_name, version) SELECT index_name, 1 FROM seed;
