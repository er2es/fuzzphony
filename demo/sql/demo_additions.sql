-- Rows and lines the demo adds on top of its seeds, so that the same words work on every search page.
-- Idempotent: docker/init.sh runs it on every start (after the schema, so the sync triggers see the rows and
-- the worker indexes them; a fresh volume indexes them with the first reindex), which is why an existing demo
-- volume gets them without `docker compose down -v`.

-- A few televisions in the catalogue (the benchmark seed has none): `tv` and `television` have something to
-- find on the Compare, Playground and Relevance pages, as they do in each language of the Languages page.
INSERT INTO bench_product (id, name, description, brand_id, category_id, price, in_stock, popularity, published_at) VALUES
(2000000001, 'Smart Television 55 inch 1001', 'A smart television with a voice remote and three HDMI ports, ideal for the living room.', 7, 6, 129900, true, 8.5, now() - interval '20 days'),
(2000000002, 'OLED TV 65 inch 1002', 'An OLED TV with local dimming and a 120 Hz panel, ideal for movies and gaming.', 3, 6, 289900, true, 9.1, now() - interval '5 days'),
(2000000003, 'TV Wall Bracket 1003', 'A tilting TV wall bracket for screens up to 65 inch, ideal for the living room.', 1, 10, 7900, true, 6.2, now() - interval '60 days')
ON CONFLICT (id) DO NOTHING;

-- A television in every language of the Languages page.
INSERT INTO lang_product (id, lang, name, description, category) VALUES
(1100, 'en', 'Smart Television 55 inch', 'Ultra HD smart TV with a voice remote and three HDMI ports.', 'Electronics'),
(2100, 'de', 'Smart-Fernseher 55 Zoll', 'Ultra-HD-Fernseher mit Sprachfernbedienung und drei HDMI-Anschlüssen.', 'Elektronik'),
(3100, 'fr', 'Télévision connectée 55 pouces', 'Téléviseur Ultra HD avec télécommande vocale et trois ports HDMI.', 'Électronique'),
(4100, 'es', 'Televisor inteligente de 55 pulgadas', 'Televisor Ultra HD con mando por voz y tres puertos HDMI.', 'Electrónica'),
(5100, 'hu', 'Okos televízió 55 collos', 'Ultra HD televízió hangvezérlős távirányítóval és három HDMI-aljzattal.', 'Elektronika')
ON CONFLICT (id) DO NOTHING;
