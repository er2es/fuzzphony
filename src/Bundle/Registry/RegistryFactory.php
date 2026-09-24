<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Registry\IndexRegistry;

/** @internal Attribute indexes first, then YAML: overrides for known names, new indexes otherwise. */
final class RegistryFactory
{
    /** @param array<string, array<string, mixed>> $yaml */
    public static function create(array $yaml, ?DoctrineIndexDiscovery $discovery = null): IndexRegistry
    {
        $registry = new IndexRegistry($discovery?->discover() ?? []);
        $loader = new ArrayDefinitionLoader();
        foreach ($yaml as $name => $config) {
            $registry->register($registry->has($name) ? $loader->override($registry->get($name), $config) : $loader->load($name, $config));
        }

        return $registry;
    }
}
