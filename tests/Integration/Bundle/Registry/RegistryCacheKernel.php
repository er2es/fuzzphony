<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Bundle\Registry;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A real Symfony kernel (FrameworkBundle + FuzzphonyBundle) with a real, compiled container and a real cache.system,
 * whose "doctrine.orm.entity_manager" is a real EntityManager that counts how often the entity metadata is enumerated
 * (what the #[Searchable] discovery does). DoctrineBundle itself is not installed, so a stub extension registered
 * under the alias "doctrine" makes FuzzphonyBundle switch its ORM wiring on.
 *
 * @internal
 */
final class RegistryCacheKernel extends Kernel
{
    use MicroKernelTrait;

    /** How many times the metadata of all entities was enumerated (by any kernel of this process). */
    public static int $metadataEnumerations = 0;

    public function __construct(private readonly string $cacheDir, bool $debug)
    {
        parent::__construct('prod', $debug);
    }

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
        return $this->cacheDir . '/cache';
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): string
    {
        return $this->cacheDir . '/log';
    }

    public static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../../Fixtures/Doctrine'], true);
        $config->enableNativeLazyObjects(true);
        $config->setClassMetadataFactoryName(CountingMetadataFactory::class);

        return new EntityManager(DoctrineTestCase::dbalConnection(), $config);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'fuzzphony-test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => false],
        ]);

        // the bundle wires the ORM only when `orm:` is configured (the real DoctrineBundle's config tree is not loaded here: any array will do)
        $container->extension('doctrine', ['orm' => ['auto_mapping' => true]]);

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)->factory([DoctrineTestCase::class, 'dbalConnection']);
        $services->set('doctrine.orm.entity_manager', EntityManagerInterface::class)->factory([self::class, 'entityManager']);
        $services->set('logger', NullLogger::class);
        // private services are inlined/removed from a compiled container: reach the two under test through public aliases
        $services->alias('test.registry', 'fuzzphony.registry')->public();
        $services->alias('test.cache', 'cache.system')->public();
    }
}
