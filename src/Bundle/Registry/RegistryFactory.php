<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Psr\Cache\CacheItemPoolInterface;

/** @internal Attribute indexes first, then YAML: overrides for known names, new indexes otherwise. */
final class RegistryFactory
{
    private const string DISCOVERY_CACHE_KEY = 'fuzzphony_discovered_indexes';

    /**
     * @param array<string, array<string, mixed>> $yaml
     * @param ?CacheItemPoolInterface             $cache Keeps the discovered definitions between requests (the bundle passes cache.system outside debug mode, so cache:clear drops it like Doctrine's own metadata cache); the YAML part is always rebuilt.
     */
    public static function create(array $yaml, ?DoctrineIndexDiscovery $discovery = null, ?CacheItemPoolInterface $cache = null): IndexRegistry
    {
        $registry = new IndexRegistry($discovery === null ? [] : self::discovered($discovery, $cache));
        $loader = new ArrayDefinitionLoader();
        foreach ($yaml as $name => $config) {
            $registry->register($registry->has($name) ? $loader->override($registry->get($name), $config) : $loader->load($name, $config));
        }

        return $registry;
    }

    /** @return list<IndexDefinition> */
    private static function discovered(DoctrineIndexDiscovery $discovery, ?CacheItemPoolInterface $cache): array
    {
        if ($cache === null) {
            return $discovery->discover();
        }
        $item = $cache->getItem(self::DISCOVERY_CACHE_KEY);
        $cached = $item->get();
        if ($item->isHit() && is_array($cached)) {
            $valid = array_values(array_filter($cached, static fn(mixed $d): bool => $d instanceof IndexDefinition));
            if (count($valid) === count($cached)) {
                return $valid;
            }
        }
        $definitions = $discovery->discover();
        $cache->save($item->set($definitions));

        return $definitions;
    }
}
