<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
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
     * @return list<array{hit: Hit, entity: T}> hits whose entity was deleted meanwhile are skipped
     */
    public function load(SearchResult $result, string $class): array
    {
        if ($result->hits === []) {
            return [];
        }
        $metadata = $this->entityManager->getClassMetadata($class);
        $idField = $metadata->getIdentifierFieldNames()[0];
        $entities = [];
        foreach ($this->entityManager->getRepository($class)->findBy([$idField => $result->ids()]) as $entity) {
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
}
