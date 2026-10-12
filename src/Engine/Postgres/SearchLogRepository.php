<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\SearchRecord;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;

/**
 * @internal The SQL of the search log: one batched INSERT, a purge by age and the two reports.
 * Reports count only the searches that ran ('ok') and group by the normalised text.
 */
final readonly class SearchLogRepository
{
    /** Timestamps go in as text with their offset and are cast by PostgreSQL, so the session's time zone never matters. */
    private const string TIMESTAMP = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection, private Names $names) {}

    /**
     * @param list<SearchRecord> $records
     *
     * @return int the number of rows inserted (no statement runs for an empty list)
     */
    public function write(array $records): int
    {
        if ($records === []) {
            return 0;
        }
        $values = [];
        $params = [];
        foreach (array_values($records) as $i => $r) {
            $values[] = sprintf('(CAST(:at%1$d AS timestamptz), :ix%1$d, :tn%1$d, :us%1$d, :q%1$d, :qn%1$d, :h%1$d, :ms%1$d, CAST(:fz%1$d AS boolean), :st%1$d)', $i);
            $params += [
                "at$i" => $r->at->format(self::TIMESTAMP),
                "ix$i" => $r->index,
                "tn$i" => $r->tenant === null ? null : (string) $r->tenant,
                "us$i" => $r->user,
                "q$i" => $r->query,
                "qn$i" => $r->normalised(),
                "h$i" => $r->hits,
                "ms$i" => $r->tookMs,
                "fz$i" => $r->usedFuzzy ? 't' : 'f',
                "st$i" => $r->status->value,
            ];
        }

        return $this->connection->execute(sprintf(
            'INSERT INTO %s (at, index_name, tenant, actor_user, query, query_norm, hits, took_ms, used_fuzzy, status) VALUES %s',
            $this->names->searchLog(),
            implode(', ', $values),
        ), $params);
    }

    /** @return int the number of rows deleted: those older than $before */
    public function purge(\DateTimeInterface $before): int
    {
        return $this->connection->execute(
            sprintf('DELETE FROM %s WHERE at < CAST(:before AS timestamptz)', $this->names->searchLog()),
            ['before' => $before->format(self::TIMESTAMP)],
        );
    }

    /**
     * @param int|string|null $tenant null = no tenant filter
     * @param bool            $zeroOnly only the searches that found nothing
     *
     * @return list<QueryStat>
     */
    public function top(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $zeroOnly): array
    {
        $params = ['ix' => $index, 'from' => $from->format(self::TIMESTAMP), 'lim' => $limit];
        $where = "index_name = :ix AND status = 'ok' AND at >= CAST(:from AS timestamptz)";
        if ($to !== null) {
            $where .= ' AND at < CAST(:to AS timestamptz)';
            $params['to'] = $to->format(self::TIMESTAMP);
        }
        if ($tenant !== null) {
            $where .= ' AND tenant = :tn';
            $params['tn'] = (string) $tenant;
        }
        if ($zeroOnly) {
            $where .= ' AND hits = 0';
        }
        $rows = $this->connection->fetchAll(sprintf(
            'SELECT query_norm AS query, count(*)::int AS searches, avg(hits)::float8 AS avg_hits, max(at) AS last_seen FROM %s WHERE %s GROUP BY query_norm ORDER BY searches DESC, query_norm LIMIT :lim',
            $this->names->searchLog(),
            $where,
        ), $params);

        return array_map(static fn(array $r): QueryStat => new QueryStat(
            Coerce::str($r['query']),
            Coerce::int($r['searches']),
            Coerce::float($r['avg_hits']),
            new \DateTimeImmutable(Coerce::str($r['last_seen'])),
        ), $rows);
    }
}
