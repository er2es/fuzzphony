<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\AdminPanel;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Fuzzphony\Tests\Integration\Bundle\Registry\DoctrineStubBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The container of the admin panel recipe: FrameworkBundle and FuzzphonyBundle with a real EntityManager that maps
 * Customer. DoctrineBundle and API Platform's own bundle are not installed in this repository: a stub registers the
 * "doctrine" extension alias (it makes FuzzphonyBundle wire the ORM), the EntityManager is built by hand, and
 * the filters are applied by the test the way API Platform's collection provider applies them.
 *
 * @internal
 */
final class AdminPanelKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineStubBundle();
        yield new FuzzphonyBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->scratchDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->scratchDir() . '/log';
    }

    public static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__], true);
        $config->enableNativeLazyObjects(true);

        return new EntityManager(DoctrineTestCase::dbalConnection(), $config);
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
        $container->extension('doctrine', ['orm' => ['auto_mapping' => true]]);

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)->factory([DoctrineTestCase::class, 'dbalConnection']);
        $services->set('doctrine.orm.entity_manager', EntityManagerInterface::class)->factory([self::class, 'entityManager'])->public();
        $services->set('logger', NullLogger::class);
        // private services are removed from a compiled container: the test reaches them through public aliases
        $services->alias('recipe.filter', 'Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter')->public();
        $services->alias('recipe.orm_sync_listener', 'fuzzphony.orm_sync_listener')->public();
    }

    /** Keyed by checkout and Symfony version: a compiled container from another vendor/ tree must never be reused. */
    private function scratchDir(): string
    {
        return sys_get_temp_dir() . '/fuzzphony-recipe-admin-panel-' . substr(md5(__DIR__ . '|' . self::VERSION), 0, 10);
    }
}
