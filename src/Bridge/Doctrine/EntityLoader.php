<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\SearchResult;

/** Turns hits into entities with ONE query, keeping the ranking order. */
final readonly class EntityLoader
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<array{hit: Hit, entity: T}> hits whose entity was deleted meanwhile, or whose id cannot be an id of the entity (a string for an integer key), are skipped
     */
    public function load(SearchResult $result, string $class): array
    {
        if ($result->hits === []) {
            return [];
        }
        $metadata = $this->entityManager->getClassMetadata($class);
        $idField = $metadata->getIdentifierFieldNames()[0];
        $ids = self::fittingIds($metadata, $idField, $result->ids());
        if ($ids === []) {
            return [];
        }
        $entities = [];
        foreach ($this->entityManager->getRepository($class)->findBy([$idField => $ids]) as $entity) {
            $id = $metadata->getIdentifierValues($entity)[$idField] ?? null;
            $entities[(string) (is_scalar($id) || $id instanceof \Stringable ? $id : '')] = $entity;
        }

        $out = [];
        foreach ($result->hits as $hit) {
            if (isset($entities[(string) $hit->id])) {
                $out[] = ['hit' => $hit, 'entity' => $entities[(string) $hit->id]];
            }
        }

        return $out;
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
