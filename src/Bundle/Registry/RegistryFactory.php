<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\IndexDefinitionClass;
use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Psr\Cache\CacheItemPoolInterface;

/** @internal Attribute indexes first, then YAML: overrides for known names, new indexes otherwise. */
final class RegistryFactory
{
    /** The violation reported (build time, doctor) for a field with `granted` while the authorization guard is off. */
    public const string GRANTED_WITHOUT_AUTHORIZATION = 'a field has "granted" but fuzzphony.authorization is not enabled, so every viewer would see it: set fuzzphony.authorization.enabled: true (and a voter for the role of the field).';

    private const string DISCOVERY_CACHE_KEY = 'fuzzphony_discovered_indexes';
    private const string CLASSES_CACHE_KEY = 'fuzzphony_discovered_definition_classes';

    /**
     * @param array<string, array<string, mixed>> $yaml
     * @param ?CacheItemPoolInterface             $cache Keeps the discovered definitions between requests (the bundle passes cache.system outside debug mode, so cache:clear drops it like Doctrine's own metadata cache); the YAML part is always rebuilt.
     * @param bool                                $cacheInCli Test seam (the container parameter `fuzzphony.registry_cache_in_cli`, not a configuration option): the cache serves web requests only, so a command, a worker or a consumer (PHP_SAPI "cli") always discovers fresh and neither reads nor writes the pool; true lets a test prove the cache from the command line.
     * @param ?string                             $projectDir What a relative `source.query_file` of the YAML starts at (the bundle passes kernel.project_dir).
     * @param iterable<object>                    $definitionClasses The services with #[AsIndexDefinition] (tag fuzzphony.index_definition), a lazy iterable: with a warm cache nothing is instantiated; their definitions are cached next to the attribute ones, so build() must be pure
     * @param bool                                $authorizationEnabled false: an index with a `granted` field does not build (InvalidDefinition), nothing would hide the field
     */
    public static function create(array $yaml, ?DoctrineIndexDiscovery $discovery = null, ?CacheItemPoolInterface $cache = null, bool $cacheInCli = false, ?string $projectDir = null, bool $authorizationEnabled = true, iterable $definitionClasses = []): IndexRegistry
    {
        $pool = \PHP_SAPI === 'cli' && !$cacheInCli ? null : $cache;
        $registry = new IndexRegistry($discovery === null ? [] : self::discovered($discovery, $pool));
        foreach ($registry->all() as $name => $definition) {
            $registry->annotateOrigin($name, 'attribute ' . ($definition->entityClass ?? '#[Searchable]'));
        }
        foreach (self::definitionClasses($definitionClasses, $pool) as $class => $definition) {
            $registry->register($definition, 'definition class ' . $class);
        }
        $loader = new ArrayDefinitionLoader($projectDir);
        foreach ($yaml as $name => $config) {
            $registry->register($registry->has($name) ? $loader->override($registry->get($name), $config) : $loader->load($name, $config));
            $registry->annotateOrigin($name, 'YAML');
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
        self::store($cache, $item->set($definitions));

        return $definitions;
    }

    /**
     * @param iterable<object> $services
     *
     * @return array<class-string, IndexDefinition> by the class that defines it
     */
    private static function definitionClasses(iterable $services, ?CacheItemPoolInterface $cache): array
    {
        if ($services instanceof \Countable && count($services) === 0) {
            return []; // no definition class: no cache item, no work
        }
        $item = $cache?->getItem(self::CLASSES_CACHE_KEY);
        $cached = $item?->get();
        if ($item !== null && $item->isHit() && is_array($cached)) {
            $valid = array_filter($cached, static fn(mixed $d, mixed $class): bool => $d instanceof IndexDefinition && is_string($class), \ARRAY_FILTER_USE_BOTH);
            if (count($valid) === count($cached)) {
                /** @var array<class-string, IndexDefinition> $valid */
                return $valid;
            }
        }
        $definitions = [];
        foreach ($services as $service) {
            $definitions[$service::class] = IndexDefinitionClass::fromInstance($service);
        }
        if ($cache !== null && $item !== null) {
            self::store($cache, $item->set($definitions));
        }

        return $definitions;
    }

    /** A cache that cannot be written (a read-only directory, a full disk) only costs the next request the discovery: never an error. */
    private static function store(CacheItemPoolInterface $cache, \Psr\Cache\CacheItemInterface $item): void
    {
        try {
            $cache->save($item);
        } catch (\Throwable) {
        }
    }
}
