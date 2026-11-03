<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Psr\Cache\CacheItemPoolInterface;

/** @internal Attribute indexes first, then YAML: overrides for known names, new indexes otherwise. */
final class RegistryFactory
{
    /** The violation reported (build time, doctor) for a field with `granted` while the authorization guard is off. */
    public const string GRANTED_WITHOUT_AUTHORIZATION = 'a field has "granted" but fuzzphony.authorization is not enabled, so every viewer would see it: set fuzzphony.authorization.enabled: true (and a voter for the role of the field).';

    private const string DISCOVERY_CACHE_KEY = 'fuzzphony_discovered_indexes';

    /**
     * @param array<string, array<string, mixed>> $yaml
     * @param ?CacheItemPoolInterface             $cache Keeps the discovered definitions between requests (the bundle passes cache.system outside debug mode, so cache:clear drops it like Doctrine's own metadata cache); the YAML part is always rebuilt.
     * @param bool                                $cacheInCli Test seam (the container parameter `fuzzphony.registry_cache_in_cli`, not a configuration option): the cache serves web requests only, so a command, a worker or a consumer (PHP_SAPI "cli") always discovers fresh and neither reads nor writes the pool; true lets a test prove the cache from the command line.
     * @param ?string                             $projectDir What a relative `source.query_file` of the YAML starts at (the bundle passes kernel.project_dir).
     * @param bool                                $authorizationEnabled false: an index with a `granted` field does not build (InvalidDefinition), nothing would hide the field
     */
    public static function create(array $yaml, ?DoctrineIndexDiscovery $discovery = null, ?CacheItemPoolInterface $cache = null, bool $cacheInCli = false, ?string $projectDir = null, bool $authorizationEnabled = true): IndexRegistry
    {
        $registry = new IndexRegistry($discovery === null ? [] : self::discovered($discovery, \PHP_SAPI === 'cli' && !$cacheInCli ? null : $cache));
        $loader = new ArrayDefinitionLoader($projectDir);
        foreach ($yaml as $name => $config) {
            $registry->register($registry->has($name) ? $loader->override($registry->get($name), $config) : $loader->load($name, $config));
        }

        if (!$authorizationEnabled) {
            foreach ($registry->all() as $definition) {
                if ($definition->hasGrantedField()) {
                    throw new InvalidDefinition($definition->name, [self::GRANTED_WITHOUT_AUTHORIZATION]);
                }
            }
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
