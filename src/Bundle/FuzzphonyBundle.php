<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Bundle\Messenger\MessengerRefreshDispatcher;
use Fuzzphony\Bundle\Messenger\RefreshDocumentsHandler;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Bundle\Twig\SearchComponent;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Core\Sync\RefreshDispatcher;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * config/packages/fuzzphony.yaml — everything is optional:
 *
 *   fuzzphony:
 *     connection: default          # DBAL connection name
 *     extension_schema: public     # where pg_trgm / unaccent live
 *     discover_entities: true      # pick up #[Searchable] entities automatically
 *     worker: { batch_size: 500, idle_sleep: 1.0 }
 *     orm_sync: { async: false }   # true: refresh through Messenger (route RefreshDocuments to a transport)
 *     indexes:
 *       products:                  # overrides for an attribute index, or a pure YAML index
 *         thresholds: { min_score: 0.05 }
 *         profiles: { popular: { boost: 0.05, recency: 0.3 } }
 */
final class FuzzphonyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'fuzzphony';

    /** AbstractBundle assumes the class lives one level below the package root; here it IS the root. */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('connection')->defaultValue('default')->info('Doctrine DBAL connection name')->end()
                ->scalarNode('extension_schema')->defaultValue('public')->info('Schema of the pg_trgm and unaccent extensions')->end()
                ->booleanNode('discover_entities')->defaultTrue()->info('Register every Doctrine entity with #[Searchable]')->end()
                ->arrayNode('worker')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')->defaultValue(500)->min(1)->end()
                        ->floatNode('idle_sleep')->defaultValue(1.0)->min(0.05)->end()
                    ->end()
                ->end()
                ->arrayNode('orm_sync')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('async')->defaultFalse()->info('Dispatch Fuzzphony\\Bundle\\Messenger\\RefreshDocuments instead of refreshing inside the request')->end()
                        ->integerNode('chunk_size')->defaultValue(500)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('indexes')
                    ->info('Pure YAML indexes, or overrides (YAML wins) for attribute-defined ones. Validated by Fuzzphony with precise error messages.')
                    ->useAttributeAsKey('name')
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire(false)->autoconfigure(false);
        $hasOrm = interface_exists(EntityManagerInterface::class) && $builder->hasExtension('doctrine');

        // configure()'s tree guarantees these shapes/types; narrowed here because AbstractBundle
        // declares $config as plain array (a stricter @param on the override would violate LSP).
        $connectionRaw = Coerce::str($config['connection'] ?? null);
        $connectionName = $connectionRaw !== '' ? $connectionRaw : 'default';
        $extensionSchemaRaw = Coerce::str($config['extension_schema'] ?? null);
        $extensionSchema = $extensionSchemaRaw !== '' ? $extensionSchemaRaw : 'public';
        $discoverEntities = (bool) ($config['discover_entities'] ?? true);
        $indexes = is_array($config['indexes'] ?? null) ? $config['indexes'] : [];
        $worker = is_array($config['worker'] ?? null) ? $config['worker'] : [];
        $workerBatchSizeRaw = Coerce::int($worker['batch_size'] ?? null);
        $workerBatchSize = $workerBatchSizeRaw !== 0 ? $workerBatchSizeRaw : 500;
        $workerIdleSleepRaw = Coerce::float($worker['idle_sleep'] ?? null);
        $workerIdleSleep = $workerIdleSleepRaw !== 0.0 ? $workerIdleSleepRaw : 1.0;
        $ormSync = is_array($config['orm_sync'] ?? null) ? $config['orm_sync'] : [];
        $ormSyncAsync = (bool) ($ormSync['async'] ?? false);
        $ormSyncChunkSizeRaw = Coerce::int($ormSync['chunk_size'] ?? null);
        $ormSyncChunkSize = $ormSyncChunkSizeRaw !== 0 ? $ormSyncChunkSizeRaw : 500;

        $services->set('fuzzphony.connection', DbalConnection::class)
            ->args([service(sprintf('doctrine.dbal.%s_connection', $connectionName))]);
        $services->alias(Connection::class, 'fuzzphony.connection');

        $services->set('fuzzphony.engine', PostgresEngine::class)
            ->args([service('fuzzphony.connection'), $extensionSchema]);
        $services->alias(Engine::class, 'fuzzphony.engine')->public();

        if ($hasOrm) {
            $services->set('fuzzphony.discovery', DoctrineIndexDiscovery::class)
                ->args([service('doctrine.orm.entity_manager')]);
            $services->set('fuzzphony.entity_loader', EntityLoader::class)
                ->args([service('doctrine.orm.entity_manager')]);
            $services->alias(EntityLoader::class, 'fuzzphony.entity_loader')->public();
        }

        $services->set('fuzzphony.registry', IndexRegistry::class)
            ->factory([RegistryFactory::class, 'create'])
            ->args([
                $indexes,
                $hasOrm && $discoverEntities ? service('fuzzphony.discovery') : null,
            ]);
        $services->alias(IndexRegistry::class, 'fuzzphony.registry');

        $services->set('fuzzphony', Fuzzphony::class)
            ->args([service('fuzzphony.engine'), service('fuzzphony.registry')]);
        $services->alias(Fuzzphony::class, 'fuzzphony')->public();

        $services->set('fuzzphony.introspector', PostgresIntrospector::class)->args([service('fuzzphony.connection')]);
        $services->alias(SourceIntrospector::class, 'fuzzphony.introspector');

        if ($ormSyncAsync) {
            if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
                // symfony/messenger is a dev dependency, so the test run can't reach this line.
                throw new \LogicException('fuzzphony.orm_sync.async requires symfony/messenger: composer require symfony/messenger'); // @codeCoverageIgnore
            }
            $services->set('fuzzphony.refresh_dispatcher', MessengerRefreshDispatcher::class)
                ->args([service('messenger.default_bus'), $ormSyncChunkSize]);
        } else {
            $services->set('fuzzphony.refresh_dispatcher', ImmediateRefreshDispatcher::class)->args([service('fuzzphony.engine')]);
        }
        $services->alias(RefreshDispatcher::class, 'fuzzphony.refresh_dispatcher');
        if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $services->set('fuzzphony.messenger.refresh_handler', RefreshDocumentsHandler::class)
                ->args([service('fuzzphony')])
                ->tag('messenger.message_handler');
        }

        if (interface_exists(\ApiPlatform\Doctrine\Orm\Filter\FilterInterface::class)) {
            // #[ApiFilter(FuzzphonySearchFilter::class)] creates per-resource copies of this definition
            $services->set(FuzzphonySearchFilter::class)
                ->args([service('fuzzphony')])
                ->autowire()
                ->tag('api_platform.filter');
        }

        if (class_exists(\Symfony\UX\LiveComponent\Attribute\AsLiveComponent::class)) {
            $services->set(SearchComponent::class)
                ->args([service('fuzzphony')])
                ->autoconfigure();
        }

        if ($hasOrm) {
            $listener = $services->set('fuzzphony.orm_sync_listener', OrmSyncListener::class)
                ->args([service('fuzzphony.refresh_dispatcher'), service('fuzzphony.registry')]);
            foreach (['postPersist', 'postUpdate', 'preRemove', 'postFlush'] as $event) {
                $listener->tag('doctrine.event_listener', ['event' => $event, 'connection' => $connectionName]);
            }
        }

        $commands = [
            SchemaCommand::class => [service('fuzzphony'), service('fuzzphony.connection')],
            DoctorCommand::class => [service('fuzzphony')],
            ReindexCommand::class => [service('fuzzphony')],
            WorkerCommand::class => [service('fuzzphony'), $workerBatchSize, $workerIdleSleep],
            SearchCommand::class => [service('fuzzphony')],
            WizardCommand::class => [service('fuzzphony.introspector'), service('fuzzphony.engine'), service('fuzzphony.connection')],
        ];
        foreach ($commands as $class => $args) {
            $services->set($class)->args($args)->tag('console.command');
        }
    }
}
