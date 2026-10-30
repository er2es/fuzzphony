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

-- A small family of projectors for the Tune page: one word, twelve products that differ in everything a ranking setting looks at
-- (title match: the whole title, the start of it, the middle, the description only; popularity; age; price; stock), so that every
-- slider reorders or shrinks the list. The base catalogue cannot show it: its products of one word differ in nothing but the number.
INSERT INTO bench_product (id, name, description, brand_id, category_id, price, in_stock, popularity, published_at) VALUES
(2000000011, 'Projector', 'A basic projector for presentations in a small meeting room.', 9, 6, 39900, true, 4.0, now() - interval '300 days'),
(2000000012, 'Projector Screen 100 inch', 'A wall-mounted screen for any projector, ideal for the living room.', 4, 10, 24900, true, 5.5, now() - interval '90 days'),
(2000000013, 'Projector Ceiling Mount 1013', 'A telescopic ceiling mount that holds a projector up to 15 kg.', 1, 10, 8900, true, 3.0, now() - interval '600 days'),
(2000000014, 'Projector Lamp 240 W', 'A spare lamp for older projector models, rated for 3000 hours.', 6, 8, 19900, false, 7.2, now() - interval '200 days'),
(2000000015, 'Portable HD Projector', 'A light HD projector with a built-in speaker, ideal for travel.', 3, 6, 89900, true, 9.0, now() - interval '40 days'),
(2000000016, 'Mini Pocket Projector 1016', 'A pocket-sized projector that fits a jacket and runs for two hours.', 2, 6, 54900, true, 8.1, now() - interval '4 days'),
(2000000017, 'Laser 4K Home Cinema Projector', 'A laser projector with a 4K panel and a 20 000 hour light source, ideal for movies.', 7, 6, 459900, true, 9.6, now() - interval '120 days'),
(2000000018, 'Smart Gaming Projector 1018', 'A smart projector with a low-latency mode, ideal for gaming.', 8, 6, 139900, true, 6.5, now() - interval '8 days'),
(2000000019, 'HDMI Cable 3 m', 'Connects a laptop to a projector or a monitor.', 5, 10, 2900, true, 9.9, now() - interval '2 days'),
(2000000020, 'Presentation Remote Clicker', 'A laser pointer and slide clicker that works with any projector.', 10, 10, 7900, true, 8.8, now() - interval '1 day'),
(2000000021, 'Lens Cleaning Kit', 'Wipes and a spray for camera lenses, monitors and projector lenses.', 9, 7, 3900, true, 1.5, now() - interval '800 days'),
(2000000022, 'Projector Carry Case 1022', 'A padded case for a projector, its cables and a remote.', 4, 10, 12900, true, 6.0, now() - interval '25 days')
ON CONFLICT (id) DO NOTHING;

-- A television in every language of the Languages page.
INSERT INTO lang_product (id, lang, name, description, category) VALUES
(1100, 'en', 'Smart Television 55 inch', 'Ultra HD smart TV with a voice remote and three HDMI ports.', 'Electronics'),
(2100, 'de', 'Smart-Fernseher 55 Zoll', 'Ultra-HD-Fernseher mit Sprachfernbedienung und drei HDMI-Anschlüssen.', 'Elektronik'),
(3100, 'fr', 'Télévision connectée 55 pouces', 'Téléviseur Ultra HD avec télécommande vocale et trois ports HDMI.', 'Électronique'),
(4100, 'es', 'Televisor inteligente de 55 pulgadas', 'Televisor Ultra HD con mando por voz y tres puertos HDMI.', 'Electrónica'),
(5100, 'hu', 'Okos televízió 55 collos', 'Ultra HD televízió hangvezérlős távirányítóval és három HDMI-aljzattal.', 'Elektronika')
ON CONFLICT (id) DO NOTHING;
