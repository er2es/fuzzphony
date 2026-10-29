<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Middleware as DbalMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Tests\Integration\PostgresTestCase;

/** @internal Builds a real Doctrine ORM EntityManager against the same disposable Postgres used by PostgresTestCase. */
final class DoctrineTestCase
{
    /**
     * The ORM configuration the tests use, for the oldest ORM the library supports (3.3: the long method name, no native lazy objects)
     * and the newest alike.
     *
     * @param list<string> $paths
     */
    public static function attributeConfig(array $paths): Configuration
    {
        // @phpstan-ignore function.alreadyNarrowedType (always true with the newest ORM, false with 3.3)
        $config = method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true);
        // @phpstan-ignore function.alreadyNarrowedType (always true with the newest ORM, false with 3.3)
        if (method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        return $config;
    }

    /** @param list<DbalMiddleware> $middlewares DBAL middlewares must be registered before the connection is first used. */
    public static function entityManager(array $middlewares = []): EntityManagerInterface
    {
        $config = self::attributeConfig([__DIR__ . '/../../Fixtures/Doctrine']);

        return new EntityManager(self::dbalConnection($middlewares), $config);
    }

    /** @param list<DbalMiddleware> $middlewares DBAL middlewares must be registered before the connection is first used. */
    public static function dbalConnection(array $middlewares = []): DbalConnection
    {
        $dsn = PostgresTestCase::dsn();

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares($middlewares);

        return DriverManager::getConnection(self::dbalParams($dsn), $dbalConfig);
    }

    public static function createArticleTable(Connection $connection): void
    {
        // fuzzphony_articles is the sidecar index table for the "articles" index; schema application
        // is idempotent (CREATE TABLE IF NOT EXISTS), so it must be dropped explicitly between tests.
        $connection->execute('DROP TABLE IF EXISTS fz_article, fuzzphony_articles CASCADE');
        $connection->execute(<<<'SQL'
            CREATE TABLE fz_article (
                id integer PRIMARY KEY, title text NOT NULL, body text, published boolean NOT NULL DEFAULT true
            )
            SQL);
    }

    /** @return array{driver: 'pdo_pgsql', host: string, port: int, dbname: string, user?: string, password?: string} */
    private static function dbalParams(string $dsn): array
    {
        $body = substr($dsn, (int) strpos($dsn, ':') + 1);
        parse_str(str_replace(';', '&', $body), $parts);

        $params = [
            'driver' => 'pdo_pgsql',
            'host' => is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1',
            'port' => is_numeric($parts['port'] ?? null) ? (int) $parts['port'] : 5432,
            'dbname' => is_string($parts['dbname'] ?? null) ? $parts['dbname'] : '',
        ];
        if (is_string($parts['user'] ?? null)) {
            $params['user'] = $parts['user'];
        }
        if (is_string($parts['password'] ?? null)) {
            $params['password'] = $parts['password'];
        }

        return $params;
    }
}
