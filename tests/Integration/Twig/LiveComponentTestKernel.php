<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Twig;

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
 * A minimal but real Symfony kernel with just enough bundles to render a Fuzzphony live component:
 * FrameworkBundle, TwigBundle, Stimulus, UX Twig Component, UX Live Component and FuzzphonyBundle,
 * pointed at the same disposable Postgres as the other integration tests.
 *
 * @internal
 */
final class LiveComponentTestKernel extends Kernel
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

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/fuzzphony-live-component-test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/fuzzphony-live-component-test/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'fuzzphony-test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
            'property_access' => true,
        ]);
        // WORKAROUND for a known bug in the bundle itself (see FuzzphonyBundleTest::testTemplatesAreWhereTwigBundleLooksForThem):
        // FuzzphonyBundle::getPath() resolves one directory too high for this package's layout, so TwigBundle never
        // registers the bundle's "@Fuzzphony" template namespace on its own. Registering it here by hand lets these
        // tests exercise SearchComponent's real behaviour instead of dying on the missing namespace.
        $container->extension('twig', [
            'paths' => [self::bundleDirectory() . '/templates' => 'Fuzzphony'],
        ]);
        $container->extension('twig_component', [
            'defaults' => ['Fuzzphony\\Tests\\Integration\\Twig\\Components\\' => 'components/'],
            'anonymous_template_directory' => 'components/',
        ]);
        $container->extension('fuzzphony', [
            'indexes' => [
                'articles' => [
                    'source' => ['table' => 'fz_article', 'id' => 'id'],
                    'fields' => ['title' => ['weight' => 'A', 'fuzzy' => true], 'body' => 'D'],
                    'filters' => ['published' => 'bool'],
                    'sync' => 'manual',
                    'language' => 'english',
                    'unaccent' => false,
                ],
            ],
        ]);

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)
            ->factory([DoctrineTestCase::class, 'dbalConnection'])
            ->public();
        // FrameworkBundle's fallback logger writes every debug event to stderr; keep test output clean.
        $services->set('logger', NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@LiveComponentBundle/config/routes.php')->prefix('/_components');
    }

    private static function bundleDirectory(): string
    {
        $file = (new \ReflectionClass(FuzzphonyBundle::class))->getFileName();
        if ($file === false) {
            throw new \LogicException('Cannot locate FuzzphonyBundle on disk.');
        }

        return dirname($file);
    }
}
