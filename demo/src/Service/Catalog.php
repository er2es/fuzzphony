<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Plain SQL helpers of the demo: the naive ILIKE search, and product rows for result ids. */
final readonly class Catalog
{
    public const string TABLE = 'bench_product';

    public function __construct(private Connection $connection) {}

    /**
     * Cheap row-count estimate for explaining timings ("searching N products"): `pg_class.reltuples`,
     * populated by autovacuum/ANALYZE. Right after seeding, before the first autovacuum runs, it can
     * still read 0, so this falls back to the exact `count(*)` (fine here: it runs once per page
     * render, not once per query).
     */
    public function estimatedProductCount(): int
    {
        $estimate = (int) $this->connection->fetchOne(
            'SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass(:t)',
            ['t' => self::TABLE],
        );

        return $estimate > 0 ? $estimate : (int) $this->connection->fetchOne('SELECT count(*) FROM ' . self::TABLE);
    }

    /** @return list<array{id: int, name: string, brand: string, price: int}> */
    public function ilike(string $text, int $limit = 20): array
    {
        $pattern = '%' . implode('%', preg_split('/\s+/', trim(addcslashes($text, '%_\\'))) ?: []) . '%';

        /** @var list<array{id: int, name: string, brand: string, price: int}> */
        return $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, b.name AS brand, p.price
             FROM ' . self::TABLE . ' p JOIN bench_brand b ON b.id = p.brand_id
             WHERE p.name ILIKE :q OR p.description ILIKE :q
             LIMIT :limit',
            ['q' => $pattern, 'limit' => $limit],
        );
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int|string, array{id: int, name: string, brand: string, category: string, price: int, popularity: float}>
     */
    public function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, b.name AS brand, c.name AS category, p.price, p.popularity
             FROM ' . self::TABLE . ' p JOIN bench_brand b ON b.id = p.brand_id JOIN bench_category c ON c.id = p.category_id
             WHERE p.id IN (:ids)',
            ['ids' => array_map('intval', $ids)],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_column($rows, null, 'id');
    }

    /**
     * For the typo-found rows of the Relevance page: which word of which field comes closest to each word of the query, by the same
     * measure typo tolerance uses (pg_trgm word_similarity) and how many trigrams the two share. Reads only the given rows; the words
     * are bound parameters (one placeholder each), nothing of them reaches the SQL text.
     *
     * @param list<int|string> $ids
     * @param list<string>     $words lower-case words of the query
     *
     * @return array<int|string, array{field: string, word: string, token: string, similarity: float, shared: int}> the best pairing of each row
     */
    public function closestWords(array $ids, array $words): array
    {
        $words = array_slice(array_values(array_unique($words)), 0, 6);
        if ($ids === [] || $words === []) {
            return [];
        }
        $values = [];
        $params = ['ids' => array_map('intval', $ids)];
        foreach ($words as $i => $word) {
            $values[] = '(:w' . $i . ')';
            $params['w' . $i] = $word;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, f.field, w.word, fw.token, word_similarity(w.word, fw.token) AS similarity,
                    cardinality(ARRAY(SELECT unnest(show_trgm(w.word)) INTERSECT SELECT unnest(show_trgm(fw.token)))) AS shared
             FROM ' . self::TABLE . ' p JOIN bench_brand b ON b.id = p.brand_id
             CROSS JOIN LATERAL (VALUES (\'name\', p.name), (\'brand\', b.name)) f(field, txt)
             CROSS JOIN LATERAL regexp_split_to_table(lower(f.txt), \'\\W+\') fw(token)
             CROSS JOIN (VALUES ' . implode(', ', $values) . ') w(word)
             WHERE p.id IN (:ids) AND fw.token <> \'\'',
            $params,
            ['ids' => ArrayParameterType::INTEGER],
        );
        $best = [];
        foreach ($rows as $row) {
            $row['similarity'] = (float) $row['similarity'];
            $row['shared'] = (int) $row['shared'];
            if (!isset($best[$row['id']]) || $row['similarity'] > $best[$row['id']]['similarity']) {
                $best[$row['id']] = $row;
            }
        }

        return array_map(static fn(array $r): array => ['field' => $r['field'], 'word' => $r['word'], 'token' => $r['token'], 'similarity' => $r['similarity'], 'shared' => $r['shared']], $best);
    }
}
