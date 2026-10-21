<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\Shop;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\LiveComponent\LiveComponentBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;

/**
 * The container of the shop recipe: the bundles of a Symfony application that has the Live Component, and the
 * index definitions of config/fuzzphony.yaml, which is the file the recipe shows. DoctrineBundle is not installed in
 * this repository, so the DBAL connection is the one the other integration tests use.
 *
 * @internal
 */
final class ShopKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new TwigComponentBundle();
        yield new LiveComponentBundle();
        yield new FuzzphonyBundle();
    }

    /** Not this directory: in debug mode Symfony writes a config/reference.php into the project's config directory. */
    public function getProjectDir(): string
    {
        return $this->scratchDir();
    }

    public function getCacheDir(): string
    {
        return $this->scratchDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->scratchDir() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'fuzzphony-recipe',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
        ]);
        $container->extension('twig', ['default_path' => __DIR__ . '/templates']);
        $container->extension('twig_component', ['defaults' => [], 'anonymous_template_directory' => 'components/']);
        $container->import(__DIR__ . '/config/fuzzphony.yaml');

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)
            ->factory([DoctrineTestCase::class, 'dbalConnection'])
            ->public();
        $services->set('logger', NullLogger::class);
        $services->set(Storefront::class)->autowire()->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@LiveComponentBundle/config/routes.php')->prefix('/_components');
    }

    /** Keyed by checkout and Symfony version: a compiled container from another vendor/ tree must never be reused. */
    private function scratchDir(): string
    {
        return sys_get_temp_dir() . '/fuzzphony-recipe-shop-' . substr(md5(__DIR__ . '|' . self::VERSION), 0, 10);
    }
}
