<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;

/** @internal Counts the enumerations of all entity metadata, which is what #[Searchable] discovery does. */
final class CountingMetadataFactory extends ClassMetadataFactory
{
    /** @return list<ClassMetadata<object>> */
    public function getAllMetadata(): array
    {
        ++RegistryCacheKernel::$metadataEnumerations;

        return parent::getAllMetadata();
    }
}
