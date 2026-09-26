<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\PdoConnection;
use PHPUnit\Framework\TestCase;

/** @internal */
final class PostgresTestCase
{
    private static ?string $dsn = null;

    /**
     * FUZZPHONY_TEST_DSN, or -- under Infection's parallel runner, which sets TEST_TOKEN (1, 2, 3,
     * ...) per worker process -- a per-process database derived from it, created on first use.
     * Without TEST_TOKEN this is exactly FUZZPHONY_TEST_DSN; every other integration test helper
     * (DoctrineTestCase, the worker connection in TruncateSyncTest) calls this too, so every
     * connection in one worker process lands on the same database.
     */
    public static function dsn(): string
    {
        if (self::$dsn !== null) {
            return self::$dsn;
        }

        $dsn = getenv('FUZZPHONY_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            TestCase::markTestSkipped('Set FUZZPHONY_TEST_DSN to a disposable PostgreSQL 15+ database to run integration tests (see docker-compose.yml).');
        }

        $token = getenv('TEST_TOKEN');

        return self::$dsn = is_string($token) && $token !== '' ? self::perProcessDsn($dsn, $token) : $dsn;
    }

    public static function connect(): Connection
    {
        return PdoConnection::fromDsn(self::dsn());
    }

    /** @param array{brands: list<array{int, string}>, products: list<array{int, string, string, int, int, bool, float, string}>} $rows */
    public static function createFixtures(Connection $connection, array $rows): void
    {
        $connection->execute('DROP TABLE IF EXISTS fz_product, fz_brand, fuzzphony_products, fuzzphony_queue CASCADE');
        $connection->execute("CREATE TABLE fz_brand (id bigint PRIMARY KEY, name text NOT NULL, country text NOT NULL DEFAULT '')");
        $connection->execute(<<<'SQL'
            CREATE TABLE fz_product (
                id bigint PRIMARY KEY, name text NOT NULL, description text, brand_id bigint NOT NULL REFERENCES fz_brand,
                price integer NOT NULL, in_stock boolean NOT NULL, popularity real NOT NULL, published_at timestamptz NOT NULL
            )
            SQL);
        foreach ($rows['brands'] as [$id, $name]) {
            $connection->execute('INSERT INTO fz_brand VALUES (:id, :name)', ['id' => $id, 'name' => $name]);
        }
        foreach ($rows['products'] as [$id, $name, $description, $brand, $price, $inStock, $popularity, $published]) {
            $connection->execute(
                'INSERT INTO fz_product VALUES (:id, :name, :description, :brand, :price, :stock, :popularity, :published)',
                ['id' => $id, 'name' => $name, 'description' => $description, 'brand' => $brand, 'price' => $price, 'stock' => $inStock, 'popularity' => $popularity, 'published' => (new \DateTimeImmutable($published))->format(DATE_ATOM)],
            );
        }
    }

    /**
     * Swaps in a "{dbname}_t{token}" database, connecting with the original $dsn (an admin
     * connection to the always-present base database) to create it if it doesn't exist yet.
     * Two workers can race here on a fresh database; CREATE DATABASE is not transactional, so the
     * loser just gets "database already exists" (SQLSTATE 42P04), which is not an error for us.
     */
    private static function perProcessDsn(string $dsn, string $token): string
    {
        if (!ctype_digit($token)) {
            return $dsn;
        }

        $matched = preg_match('/dbname=([^;]+)/', $dsn, $match);
        if ($matched !== 1) {
            return $dsn;
        }

        $perProcessName = $match[1] . '_t' . $token;

        $admin = new \PDO($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        try {
            $admin->exec('CREATE DATABASE "' . $perProcessName . '"');
        } catch (\PDOException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            if (!is_string($sqlState) || $sqlState !== '42P04') {
                throw $e;
            }
        }

        return str_replace($match[0], 'dbname=' . $perProcessName, $dsn);
    }
}
