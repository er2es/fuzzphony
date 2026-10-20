<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/** @internal Stands in for DoctrineBundle (not installed): only the "doctrine" extension alias FuzzphonyBundle looks for. */
final class DoctrineStubBundle extends Bundle
{
    public function getContainerExtension(): Extension
    {
        return new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void {}

            public function getAlias(): string
            {
                return 'doctrine';
            }
        };
    }
}
