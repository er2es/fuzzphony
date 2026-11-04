<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * @internal Fills the discovery cache of the registry at cache:warmup (prod only), so a read-only cache directory
 *           (an immutable container image) never has to be written by a web request.
 */
final class RegistryCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param array<string, array<string, mixed>> $yaml
     * @param iterable<object>                    $definitionClasses
     */
    public function __construct(
        private readonly array $yaml,
        private readonly ?DoctrineIndexDiscovery $discovery,
        private readonly ?CacheItemPoolInterface $cache,
        private readonly ?string $projectDir,
        private readonly bool $authorizationEnabled,
        private readonly iterable $definitionClasses,
    ) {}

    public function isOptional(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->cache === null) {
            return [];
        }
        RegistryFactory::create($this->yaml, $this->discovery, $this->cache, true, $this->projectDir, $this->authorizationEnabled, $this->definitionClasses);

        return [];
    }
}
