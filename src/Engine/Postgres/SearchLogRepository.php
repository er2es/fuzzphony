<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres;

use Fuzzphony\Core\Analytics\LoggedSearch;
use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\RecordStatus;
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
    private const int CHUNK = 1000;

    public function __construct(private Connection $connection, private Names $names) {}

    /**
     * @param list<SearchRecord> $records
     *
     * @return int the number of rows inserted (no statement runs for an empty list)
     */
    public function write(array $records): int
    {
        $written = 0;
        // an install that never enabled the client IP has no actor_ip column: the column is named only when a record carries an address
        $withIp = array_any($records, static fn(SearchRecord $r): bool => $r->ip !== null);
        // at most 11 bind parameters a row: PostgreSQL allows 65,535 a statement, so at most 1,000 rows each
        foreach (array_chunk(array_values($records), self::CHUNK) as $chunk) {
            $written += $this->insert($chunk, $withIp);
        }

        return $written;
    }

    /** @param list<SearchRecord> $records */
    private function insert(array $records, bool $withIp): int
    {
        $values = [];
        $params = [];
        foreach ($records as $i => $r) {
            $values[] = sprintf('(CAST(:at%1$d AS timestamptz), :ix%1$d, :tn%1$d, :us%1$d, :q%1$d, :qn%1$d, :h%1$d, :ms%1$d, CAST(:fz%1$d AS boolean), :st%1$d%2$s)', $i, $withIp ? sprintf(', :ip%d', $i) : '');
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
            if ($withIp) {
                $params["ip$i"] = $r->ip;
            }
        }

        return $this->connection->execute(sprintf(
            'INSERT INTO %s (at, index_name, tenant, actor_user, query, query_norm, hits, took_ms, used_fuzzy, status%s) VALUES %s',
            $this->names->searchLog(),
            $withIp ? ', actor_ip' : '',
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

    /**
     * @param int|string|null $tenant        null = no tenant filter
     * @param string|null     $user          exactly this user
     * @param bool            $anonymousOnly only the rows without a user (the caller does not pass both)
     *
     * @return list<LoggedSearch> newest first (the id breaks a tie in the time)
     */
    public function recent(string $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, ?string $user, bool $anonymousOnly, ?RecordStatus $status): array
    {
        $params = ['ix' => $index, 'from' => $from->format(self::TIMESTAMP), 'lim' => $limit];
        $where = 'index_name = :ix AND at >= CAST(:from AS timestamptz)';
        if ($to !== null) {
            $where .= ' AND at < CAST(:to AS timestamptz)';
            $params['to'] = $to->format(self::TIMESTAMP);
        }
        if ($tenant !== null) {
            $where .= ' AND tenant = :tn';
            $params['tn'] = (string) $tenant;
        }
        if ($user !== null) {
            $where .= ' AND actor_user = :us';
            $params['us'] = $user;
        } elseif ($anonymousOnly) {
            $where .= ' AND actor_user IS NULL';
        }
        if ($status !== null) {
            $where .= ' AND status = :st';
            $params['st'] = $status->value;
        }
        $rows = $this->connection->fetchAll(sprintf(
            'SELECT at, index_name, tenant, actor_user, %s AS actor_ip, query, hits, took_ms, used_fuzzy, status FROM %s WHERE %s ORDER BY at DESC, id DESC LIMIT :lim',
            $this->hasIpColumn() ? 'actor_ip' : 'CAST(NULL AS text)',
            $this->names->searchLog(),
            $where,
        ), $params);

        return array_map(static fn(array $r): LoggedSearch => new LoggedSearch(
            new \DateTimeImmutable(Coerce::str($r['at'])),
            Coerce::str($r['index_name']),
            $r['tenant'] === null ? null : Coerce::str($r['tenant']),
            $r['actor_user'] === null ? null : Coerce::str($r['actor_user']),
            $r['actor_ip'] === null ? null : Coerce::str($r['actor_ip']),
            Coerce::str($r['query']),
            Coerce::int($r['hits']),
            Coerce::float($r['took_ms']),
            $r['used_fuzzy'] === true,
            RecordStatus::from(Coerce::str($r['status'])),
        ), $rows);
    }

    /** An install that never enabled the client IP has no actor_ip column (it comes with fuzzphony:schema --apply): one catalog lookup tells. */
    private function hasIpColumn(): bool
    {
        return $this->connection->fetchValue(
            "SELECT EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass(:log) AND attname = 'actor_ip' AND NOT attisdropped)",
            ['log' => $this->names->searchLog()],
        ) === true;
    }
}
