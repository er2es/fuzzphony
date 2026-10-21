<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

use Doctrine\DBAL\Connection as DbalConnection;
use Fuzzphony\Bundle\FuzzphonyBundle;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The container of the multi-tenant recipe: FrameworkBundle with the rate limiter, FuzzphonyBundle with the config the
 * recipe shows (config/fuzzphony.yaml), and the three services symfony/security-bundle provides to the guards, which is
 * not installed in this repository: the token storage, the decision manager with the voters (the recipe's SearchVoter
 * and Symfony's RoleVoter) and the authorization checker. They are the same classes the bundle wires.
 *
 * @internal
 */
final class MultiTenantKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
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
            'property_access' => true,
        ]);
        $container->import(__DIR__ . '/config/fuzzphony.yaml');
        // the only difference to the recipe's file: APCu is rarely enabled on the command line, and an in-memory pool is emptied
        // between two requests (kernel.reset), so the test keeps the limiter's state in a filesystem pool of its own (it clears it first)
        $container->extension('framework', ['cache' => ['pools' => ['cache.search_limiter' => ['adapter' => 'cache.adapter.filesystem']]]]);

        $services = $container->services();
        $services->set('doctrine.dbal.default_connection', DbalConnection::class)->factory([DoctrineTestCase::class, 'dbalConnection']);
        $services->set('logger', NullLogger::class);
        $services->set('security.token_storage', TokenStorage::class)->public();
        $services->set(SearchVoter::class);
        $services->set(RoleVoter::class);
        $services->set('security.access.decision_manager', AccessDecisionManager::class)->args([[service(SearchVoter::class), service(RoleVoter::class)]]);
        $services->set('security.authorization_checker', AuthorizationChecker::class)->args([service('security.token_storage'), service('security.access.decision_manager')]);
        $services->set(TicketSearchController::class)->autowire()->public()->tag('controller.service_arguments');
        $services->alias('recipe.limiter_pool', 'cache.search_limiter')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/TicketSearchController.php', 'attribute');
    }

    /** Keyed by checkout and Symfony version: a compiled container from another vendor/ tree must never be reused. */
    private function scratchDir(): string
    {
        return sys_get_temp_dir() . '/fuzzphony-recipe-multi-tenant-' . substr(md5(__DIR__ . '|' . self::VERSION), 0, 10);
    }
}
