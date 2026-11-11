<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Fuzzphony\Core\Exception\FuzzphonyException;
use Fuzzphony\Core\Fuzzphony;

/**
 * The sync demonstration: the city of ONE address row (erp_address #1, the first address of customer #1) is changed from the page,
 * and the page then reads what the library did about it. The write is the only one of the ERP data set and as narrow as it can be:
 * UPDATE (city) on erp_address for the application role (docker/init.sh), only to a city of a fixed list, only on that row.
 * Everything else here reads: the tables of the indexes (`indexed_at` of the document of customer #1) and the queue.
 */
final readonly class ErpSync
{
    public const int ADDRESS_ID = 1;
    public const int CUSTOMER_ID = 1;

    /** What erp_address #1 holds after `sql/erp.sql` (deterministic): the target of "Undo". */
    public const string ORIGINAL = 'Székesfehérvár';

    /** The only cities the page can write: key => city. */
    public const array CITIES = ['sopron' => 'Sopron', 'gyor' => 'Győr', 'zalaegerszeg' => 'Zalaegerszeg'];

    /** After this many seconds without a refresh the panel says the worker is slow (it keeps waiting). */
    public const int SLOW_AFTER = 10;

    /** The indexes that read erp_address and the document of customer #1 in each. */
    private const array DOCS = ['people' => '1', 'site' => 'person:1'];

    public function __construct(private Connection $connection, private Fuzzphony $fuzzphony) {}

    /** @return array{id: int, customer: string, kind: string, zip: string, city: string, street: string}|null null without the ERP rows (DEMO_ERP=0) */
    public function address(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, c.name AS customer, a.kind, a.zip, a.city, a.street FROM erp_address a JOIN erp_customer c ON c.id = a.customer_id WHERE a.id = :id AND a.customer_id = :c',
            ['id' => self::ADDRESS_ID, 'c' => self::CUSTOMER_ID],
        );

        /** @var array{id: int, customer: string, kind: string, zip: string, city: string, street: string}|false $row */
        return $row === false ? null : $row;
    }

    /**
     * The change itself, then the state the panel starts from.
     *
     * @return array{from: string, to: string, at: float, t0: string, snap: array<string, string|null>, before: array<string, mixed>}
     */
    public function move(string $city, string $customer): array
    {
        $before = $this->probe($customer, $city);
        $snap = $this->stamps();
        $from = (string) $this->connection->fetchOne('SELECT city FROM erp_address WHERE id = :id', ['id' => self::ADDRESS_ID]);
        $t0 = (string) $this->connection->fetchOne(
            'UPDATE erp_address SET city = :city WHERE id = :id AND customer_id = :c RETURNING clock_timestamp()::text',
            ['city' => $city, 'id' => self::ADDRESS_ID, 'c' => self::CUSTOMER_ID],
        );

        return ['from' => $from, 'to' => $city, 'at' => microtime(true), 't0' => $t0, 'snap' => $snap, 'before' => $before];
    }

    /**
     * What the page shows for a move: which indexes were refreshed and when, the search after, the trigger facts.
     *
     * @param array{from: string, to: string, at: float, t0: string, snap: array<string, string|null>, before: array<string, mixed>} $state
     *
     * @return array<string, mixed>
     */
    public function status(array $state, string $customer): array
    {
        $rows = [];
        $done = true;
        foreach ($this->stamps() as $index => $stamp) {
            $fresh = $stamp !== null && $stamp !== ($state['snap'][$index] ?? null);
            $done = $done && ($fresh || !isset(self::DOCS[$index]));
            $rows[$index] = ['index' => $index, 'refreshed' => isset(self::DOCS[$index]) ? $fresh : false, 'ms' => $fresh ? $this->millisSince($state['t0'], (string) $stamp) : null];
        }
        $queued = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT index_name FROM fuzzphony.fuzzphony_queue WHERE doc_id IN (:a, :b) AND index_name IN (:i1, :i2) ORDER BY 1',
            ['a' => '1', 'b' => 'person:1', 'i1' => 'people', 'i2' => 'site'],
        );
        $elapsed = microtime(true) - $state['at'];

        return [
            'from' => $state['from'],
            'to' => $state['to'],
            'rows' => $rows,
            'queued' => $queued,
            'done' => $done,
            'slow' => !$done && $elapsed > self::SLOW_AFTER,
            'elapsed' => $elapsed,
            'before' => $state['before'],
            'after' => $done ? $this->probe($customer, $state['to']) : null,
        ];
    }

    /**
     * The shared triggers of erp_address: how many there are and which indexes use them.
     *
     * @return array{count: int, names: list<string>, indexes: list<string>}
     */
    public function triggers(): array
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT tgname FROM pg_trigger WHERE tgrelid = to_regclass('erp_address') AND NOT tgisinternal ORDER BY tgname",
        );
        $indexes = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT index_name FROM fuzzphony.fuzzphony_watches WHERE replace(tbl, '\"', '') = 'erp_address' ORDER BY 1",
        );

        return ['count' => \count($names), 'names' => array_map('strval', $names), 'indexes' => array_map('strval', $indexes)];
    }

    /**
     * The search of the demonstration: the customer's name and the city, in `people`. Is customer #1 among the hits, and where?
     *
     * @return array{query: string, found: bool, rank: int|null, total: int, reason: string|null}
     */
    public function probe(string $customer, string $city): array
    {
        $query = $customer . ' ' . $city;
        try {
            $result = $this->fuzzphony->in('people')->asSystem()->query($query)->explainMatches()->limit(50)->unlogged()->get();
        } catch (FuzzphonyException) {
            return ['query' => $query, 'found' => false, 'rank' => null, 'total' => 0, 'reason' => null];
        }
        foreach ($result->hits as $rank => $hit) {
            if ($hit->id === self::CUSTOMER_ID) {
                $reason = null;
                foreach ($hit->matches ?? [] as $match) {
                    if ($match->value !== null && str_contains($match->value, ' ' . $city . ',')) {
                        $reason = sprintf('found in the address: %s', $match->value);
                        break;
                    }
                }

                return ['query' => $query, 'found' => true, 'rank' => $rank + 1, 'total' => $result->total, 'reason' => $reason];
            }
        }

        return ['query' => $query, 'found' => false, 'rank' => null, 'total' => $result->total, 'reason' => null];
    }

    /**
     * `indexed_at` of customer #1 in each index that reads erp_address, and of the first order of the customer in `orders`
     * (which does not read it, so it must not move).
     *
     * @return array<string, string|null>
     */
    private function stamps(): array
    {
        $stamps = [];
        foreach (self::DOCS as $index => $doc) {
            $stamps[$index] = $this->stamp($index, $doc);
        }
        $order = $this->connection->fetchOne('SELECT id FROM erp_order WHERE customer_id = :c ORDER BY id LIMIT 1', ['c' => self::CUSTOMER_ID]);
        $stamps['orders'] = $order === false ? null : $this->stamp('orders', (string) $order);
        $stamps['companies'] = null;

        return $stamps;
    }

    private function stamp(string $index, string $doc): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT indexed_at::text FROM fuzzphony.fuzzphony_%s WHERE id::text = :id', $index), ['id' => $doc]);

        return $value === false ? null : (string) $value;
    }

    private function millisSince(string $from, string $to): int
    {
        return (int) round((float) $this->connection->fetchOne('SELECT extract(epoch FROM (CAST(:to AS timestamptz) - CAST(:from AS timestamptz))) * 1000', ['to' => $to, 'from' => $from]));
    }
}
