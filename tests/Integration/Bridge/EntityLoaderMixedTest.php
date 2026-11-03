<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bridge;

use Doctrine\DBAL\Logging\Middleware;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Doctrine\Note;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** EntityLoader::loadHits(): hits of several sources and indexes, one query per entity class, rank order kept. */
final class EntityLoaderMixedTest extends TestCase
{
    private const string NOTE_A = '0b1d6f0e-0000-4000-8000-0000000000a1';
    private const string NOTE_B = '0b1d6f0e-0000-4000-8000-0000000000a2';

    private Connection $connection;
    private Fuzzphony $fuzzphony;
    private EntityLoader $loader;
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $selects;

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($this->connection);
        $this->connection->execute('DROP TABLE IF EXISTS fz_note, fz_free, fuzzphony_mixed, fuzzphony_arts, fuzzphony_notes_only, fuzzphony_bare CASCADE');
        $this->connection->execute('CREATE TABLE fz_note (id uuid PRIMARY KEY, title text NOT NULL)');
        $this->connection->execute('CREATE TABLE fz_free (id bigint PRIMARY KEY, title text NOT NULL)');
        $this->connection->execute("INSERT INTO fz_article (id, title) VALUES (1, 'Mouse review'), (2, 'Keyboard guide mouse mouse mouse')");
        $this->connection->execute("INSERT INTO fz_note VALUES ('" . self::NOTE_A . "', 'Mouse mouse note'), ('" . self::NOTE_B . "', 'Another note about a mouse')");
        $this->connection->execute("INSERT INTO fz_free VALUES (1, 'Free mouse')");

        $mixed = IndexDefinition::builder('mixed')
            ->source('article', 'SELECT id, title FROM fz_article', entity: Article::class, idType: 'int')
            ->source('note', 'SELECT id, title FROM fz_note', entity: Note::class, idType: 'uuid')
            ->source('free', 'SELECT id, title FROM fz_free', idType: 'int')
            ->field('title', 'A')
            ->sync('manual')
            ->build();
        $arts = IndexDefinition::builder('arts')->fromTable('fz_article')->field('title', 'A')->entity(Article::class)->sync('manual')->build();
        $notes = IndexDefinition::builder('notes_only')->fromTable('fz_note')->idType('uuid')->field('title', 'A')->entity(Note::class)->sync('manual')->build();
        $bare = IndexDefinition::builder('bare')->fromTable('fz_free')->field('title', 'A')->sync('manual')->build();
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$mixed, $arts, $notes, $bare]));
        $this->fuzzphony->schema()->apply($this->connection);
        foreach (['mixed', 'arts', 'notes_only', 'bare'] as $name) {
            $this->fuzzphony->reindex($name);
        }

        $this->selects = new \ArrayObject();
        $selects = $this->selects;
        $logger = new class ($selects) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $selects */
            public function __construct(private readonly \ArrayObject $selects) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql']) && stripos($context['sql'], 'select') === 0) {
                    $this->selects[] = $context['sql'];
                }
            }
        };
        $this->loader = new EntityLoader(DoctrineTestCase::entityManager([new Middleware($logger)]), $this->fuzzphony);
    }

    protected function tearDown(): void
    {
        $this->connection->execute('DROP TABLE IF EXISTS fz_note, fz_free, fuzzphony_mixed, fuzzphony_arts, fuzzphony_notes_only, fuzzphony_bare CASCADE');
    }

    /** @param list<object> $entities */
    private static function names(array $entities): string
    {
        return implode(',', array_map(static fn(object $e): string => $e instanceof Article ? 'a' . $e->getId() : ($e instanceof Note ? 'n' . substr($e->getId(), -2) : '?'), $entities));
    }

    public function testAMultiSourceIndexLoadsEveryClassWithOneQueryEachInRankOrder(): void
    {
        $result = $this->fuzzphony->in('mixed')->query('mouse')->get();
        $expected = array_values(array_filter(array_map(static fn(Hit $h): ?string => match ($h->kind) {
            'article' => 'a' . $h->id,
            'note' => 'n' . substr((string) $h->id, -2),
            default => null,
        }, $result->hits)));
        self::assertCount(5, $result->hits);
        self::assertCount(4, $expected);
        $kinds = array_map(static fn(Hit $h): string => $h->kind, $result->hits);
        $sorted = $kinds;
        sort($sorted);
        self::assertNotSame($sorted, $kinds, 'the fixture interleaves the kinds');

        $this->selects->exchangeArray([]);
        $entities = $this->loader->loadHits($result);

        self::assertSame(implode(',', $expected), self::names($entities), 'rank order, interleaved across the two classes; the source without an entity is skipped');
        self::assertCount(2, $this->selects, 'one query per entity class, not per hit');
    }

    public function testAFederatedResultOverTwoSingleSourceIndexesTakesTheClassFromTheDefinition(): void
    {
        $federated = $this->fuzzphony->federated()->index('arts')->index('notes_only')->query('mouse')->get();
        self::assertCount(4, $federated->hits);

        $this->selects->exchangeArray([]);
        $entities = $this->loader->loadHits($federated);

        self::assertSame(
            implode(',', array_map(static fn(Hit $h): string => $h->index === 'arts' ? 'a' . $h->id : 'n' . substr((string) $h->id, -2), $federated->hits)),
            self::names($entities),
        );
        self::assertCount(2, $this->selects);
    }

    public function testDeletedEntitiesAndGroupsWithoutAnEntityClassAreSkipped(): void
    {
        $result = $this->fuzzphony->in('mixed')->query('mouse')->get();
        $this->connection->execute('DELETE FROM fz_article WHERE id = 1');
        $bare = $this->fuzzphony->in('bare')->query('mouse')->get();
        self::assertCount(1, $bare->hits);

        $entities = $this->loader->loadHits([...$result->hits, ...$bare->hits]);

        self::assertNotContains('a1', explode(',', self::names($entities)));
        self::assertCount(3, $entities, 'the deleted article, the entity-less source and the entity-less index are skipped without an exception');
    }

    public function testIdsThatDoNotFitTheClassAreSkippedAndAnEmptyInputQueriesNothing(): void
    {
        $this->selects->exchangeArray([]);
        $odd = [new Hit('article', 'not-a-number', 1.0, new ScoreBreakdown(), index: 'mixed'), new Hit('note', 'not-a-uuid', 1.0, new ScoreBreakdown(), index: 'mixed')];

        self::assertSame([], $this->loader->loadHits($odd));
        self::assertSame([], $this->loader->loadHits([]));
        self::assertCount(0, $this->selects);
    }

    public function testWithoutTheFuzzphonyServiceItSaysSo(): void
    {
        $this->expectException(\LogicException::class);
        (new EntityLoader(DoctrineTestCase::entityManager()))->loadHits([]);
    }
}
