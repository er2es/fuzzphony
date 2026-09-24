<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;

/** Finds every mapped entity carrying #[Searchable]. Zero configuration. */
final readonly class DoctrineIndexDiscovery
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /** @return list<IndexDefinition> */
    public function discover(): array
    {
        $loader = new AttributeDefinitionLoader(new DoctrineNamingStrategy($this->entityManager));
        $definitions = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();
            if (!$metadata->isMappedSuperclass && $loader->supports($class)) {
                $definitions[] = $loader->load($class);
            }
        }

        return $definitions;
    }
}
