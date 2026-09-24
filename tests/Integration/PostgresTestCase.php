<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Database\PdoConnection;
use PHPUnit\Framework\TestCase;

/** @internal */
final class PostgresTestCase
{
    public static function connect(): Connection
    {
        $dsn = getenv('FUZZPHONY_TEST_DSN');
        if (!is_string($dsn) || $dsn === '') {
            TestCase::markTestSkipped('Set FUZZPHONY_TEST_DSN to a disposable PostgreSQL 15+ database to run integration tests (see docker-compose.yml).');
        }

        return PdoConnection::fromDsn($dsn);
    }

    /** @param array{brands: list<array{int, string}>, products: list<array{int, string, string, int, int, bool, float, string}>} $rows */
    public static function createFixtures(Connection $connection, array $rows): void
    {
        $connection->execute('DROP TABLE IF EXISTS fz_product, fz_brand, fuzzphony_products, fuzzphony_queue CASCADE');
        $connection->execute('CREATE TABLE fz_brand (id bigint PRIMARY KEY, name text NOT NULL)');
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
}
