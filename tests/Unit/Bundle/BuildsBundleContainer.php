<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** The bundle's extension loaded into a bare container, with the kernel parameters and services a real kernel always has. */
trait BuildsBundleContainer
{
    private function kernelContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', dirname(__DIR__, 3));
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('doctrine.dbal.default_connection', DbalConnection::class)->setSynthetic(true)->setPublic(true);
        $container->register('logger', \Psr\Log\LoggerInterface::class)->setSynthetic(true)->setPublic(true);

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = $this->kernelContainer();
        $extension = (new FuzzphonyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
