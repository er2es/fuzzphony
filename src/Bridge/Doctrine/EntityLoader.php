<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\FederatedResult;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchResult;

/** Turns hits into entities with ONE query, keeping the ranking order. */
final readonly class EntityLoader
{
    /** @param Fuzzphony|null $fuzzphony needed by loadHits() only: it tells which entity class a hit's index or source maps to */
    public function __construct(private EntityManagerInterface $entityManager, private ?Fuzzphony $fuzzphony = null) {}

    /**
     * Loads the entities of hits of any index and source, without naming a class: a hit's entity class is the `entity` of its source
     * (a multi-source index) or of its index definition (a single-source index). ONE query per entity class, however many hits and
     * indexes; the entities come back in the rank order of the hits (interleaved across classes). A hit is skipped, without an
     * exception, when its entity was deleted meanwhile, when its id cannot be an id of the class, or when its source or index
     * names no entity class.
     *
     * @param iterable<Hit>|SearchResult|FederatedResult $hits
     *
     * @return list<object>
     *
     * @throws \LogicException when the loader was built without a Fuzzphony service
     */
    public function loadHits(iterable|SearchResult|FederatedResult $hits): array
    {
        $fuzzphony = $this->fuzzphony ?? throw new \LogicException('EntityLoader::loadHits() needs the Fuzzphony service: new EntityLoader($entityManager, $fuzzphony).');
        $list = $hits instanceof SearchResult || $hits instanceof FederatedResult ? $hits->hits : array_values([...$hits]);
        $classes = [];
        $idsOf = [];
        foreach ($list as $position => $hit) {
            $definition = $fuzzphony->definition($hit->index);
            $class = $definition->isMultiSource() ? $definition->sourceNamed($hit->kind)?->entityClass : $definition->entityClass;
            if ($class !== null) {
                $classes[$position] = $class;
                $idsOf[$class][] = $hit->id;
            }
        }

        $entities = [];
        foreach ($idsOf as $class => $ids) {
            $metadata = $this->entityManager->getClassMetadata($class);
            $idField = $metadata->getIdentifierFieldNames()[0];
            $fitting = self::fittingIds($metadata, $idField, $ids);
            if ($fitting === []) {
                continue;
            }
            foreach ($this->entityManager->getRepository($class)->findBy([$idField => $fitting]) as $entity) {
                $id = $metadata->getIdentifierValues($entity)[$idField] ?? null;
                $entities[$class][(string) (is_scalar($id) || $id instanceof \Stringable ? $id : '')] = $entity;
            }
        }

        $out = [];
        foreach ($list as $position => $hit) {
            $entity = isset($classes[$position]) ? ($entities[$classes[$position]][(string) $hit->id] ?? null) : null;
            if ($entity !== null) {
                $out[] = $entity;
            }
        }

        return $out;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<array{hit: Hit, entity: T}> hits whose entity was deleted meanwhile, or whose id cannot be an id of the entity (a string for an integer key), are skipped
     */
    public function load(SearchResult $result, string $class): array
    {
        $hits = $this->fuzzphony === null ? $result->hits : self::hitsOfClass($this->fuzzphony, $result->hits, $class);
        if ($hits === []) {
            return [];
        }
        $metadata = $this->entityManager->getClassMetadata($class);
        $idField = $metadata->getIdentifierFieldNames()[0];
        $ids = self::fittingIds($metadata, $idField, array_map(static fn(Hit $hit): int|string => $hit->id, $hits));
        if ($ids === []) {
            return [];
        }
        $entities = [];
        foreach ($this->entityManager->getRepository($class)->findBy([$idField => $ids]) as $entity) {
            $id = $metadata->getIdentifierValues($entity)[$idField] ?? null;
            $entities[(string) (is_scalar($id) || $id instanceof \Stringable ? $id : '')] = $entity;
        }

        $out = [];
        foreach ($hits as $hit) {
            if (isset($entities[(string) $hit->id])) {
                $out[] = ['hit' => $hit, 'entity' => $entities[(string) $hit->id]];
            }
        }

        return $out;
    }

    /**
     * The hits whose id is an id of the entity class: on a multi-source index only the hits of the sources that read the class (a hit of
     * another kind has an id of another table, never to be looked up as this entity); a single-source index: every hit.
     *
     * @param list<Hit> $hits
     *
     * @return list<Hit>
     *
     * @internal
     */
    public static function hitsOfClass(Fuzzphony $fuzzphony, array $hits, string $class): array
    {
        return array_values(array_filter($hits, static function (Hit $hit) use ($fuzzphony, $class): bool {
            $definition = $fuzzphony->definition($hit->index);

            return !$definition->isMultiSource() || $definition->sourceNamed($hit->kind)?->entityClass === $class;
        }));
    }

    /**
     * The ids that can be an id of the entity: an integer key takes integers and integer strings in its range, a guid/uuid key takes UUID strings
     * (PostgreSQL would raise "invalid input syntax" for anything else); other key types take everything.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<int|string>      $ids
     *
     * @return list<int|string>
     *
     * @internal
     */
    public static function fittingIds(ClassMetadata $metadata, string $idField, array $ids): array
    {
        $type = $metadata->getTypeOfField($idField);
        $range = match ($type) {
            'smallint' => [-32768, 32767],
            'integer' => [-2147483648, 2147483647],
            'bigint' => [PHP_INT_MIN, PHP_INT_MAX],
            default => null,
        };
        if ($range !== null) {
            return array_values(array_filter($ids, static fn(int|string $id): bool => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => $range[0], 'max_range' => $range[1]]]) !== false));
        }
        if ($type === 'guid' || $type === 'uuid') {
            return array_values(array_filter($ids, static fn(int|string $id): bool => is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1));
        }

        return $ids;
    }
}
