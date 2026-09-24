<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\NamingStrategy;

/** Uses the real table / column names from Doctrine ORM mapping, so #[Searchable] needs no names. */
final readonly class DoctrineNamingStrategy implements NamingStrategy
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function table(string $class): string
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $schema = $metadata->getSchemaName();

        return ($schema !== null && $schema !== '' ? $schema . '.' : '') . $metadata->getTableName();
    }

    public function column(string $class, string $property): string
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        if ($metadata->hasField($property)) {
            return $metadata->getColumnName($property);
        }
        if ($metadata->hasAssociation($property) && $metadata->isSingleValuedAssociation($property)) {
            return $metadata->getSingleAssociationJoinColumnName($property);
        }

        return $property;
    }

    public function idColumn(string $class): string
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $identifier = $metadata->getIdentifierFieldNames();
        if (count($identifier) !== 1) {
            throw new \LogicException(sprintf('%s has a composite identifier; Fuzzphony indexes need a single-column id.', $class));
        }

        return $metadata->getColumnName($identifier[0]);
    }

    public function idType(string $class): IdType
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $type = $metadata->getTypeOfField($metadata->getIdentifierFieldNames()[0]);

        return match ($type) {
            'integer', 'bigint', 'smallint' => IdType::Int,
            'uuid', 'guid' => IdType::Uuid,
            default => IdType::String,
        };
    }
}
