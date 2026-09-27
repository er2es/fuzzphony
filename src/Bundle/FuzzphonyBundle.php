<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
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
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Core\Sync\ImmediateRefreshDispatcher;
use Fuzzphony\Core\Sync\RefreshDispatcher;
use Fuzzphony\Core\Wizard\SourceIntrospector;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Schema\Names;
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
 *     schema: public               # where Fuzzphony's own tables and functions live (e.g. fuzzphony)
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
                ->scalarNode('schema')->defaultValue('public')->info('Schema of Fuzzphony\'s own tables, functions and text search configurations (created by fuzzphony:schema --apply)')->end()
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

    /**
     * With DoctrineBundle, hide Fuzzphony's tables from Doctrine's schema tools (migrations:diff
     * would otherwise propose dropping them). An application that sets its own schema_filter keeps
     * it: merging regexes is its call. Its filter is handed to fuzzphony:doctor, which warns (with
     * the regex to merge) while it still lets Fuzzphony's tables through.
     *
     * The prepend phase sees the raw configuration, so a `connection` or `schema` given as a
     * parameter or an environment variable (`%…%`, `%env(…)%`) cannot be resolved here: then
     * nothing is prepended and the doctor does not check the filter; set the filter yourself.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $connection = 'default';
        $schema = 'public';
        foreach ($builder->getExtensionConfig('fuzzphony') as $config) {
            $connection = is_string($config['connection'] ?? null) ? $config['connection'] : $connection;
            $schema = is_string($config['schema'] ?? null) ? $config['schema'] : $schema;
        }
        if (str_contains($connection, '%') || str_contains($schema, '%')) {
            return;
        }
        $applicationFilter = null;
        foreach ($builder->getExtensionConfig('doctrine') as $doctrine) {
            $dbal = is_array($doctrine['dbal'] ?? null) ? $doctrine['dbal'] : [];
            $connections = is_array($dbal['connections'] ?? null) ? $dbal['connections'] : [];
            $named = is_array($connections[$connection] ?? null) ? $connections[$connection] : [];
            $own = $named['schema_filter'] ?? $dbal['schema_filter'] ?? null;
            $applicationFilter = is_string($own) ? $own : $applicationFilter;
        }
        if ($applicationFilter !== null) {
            $builder->setParameter('fuzzphony.app_schema_filter', $applicationFilter);

            return;
        }
        $builder->prependExtensionConfig('doctrine', ['dbal' => ['connections' => [$connection => ['schema_filter' => SchemaAssetFilter::regex($schema)]]]]);
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
        $schemaRaw = Coerce::str($config['schema'] ?? null);
        // validated here, so an invalid name fails the container build instead of the first request
        $names = new Names($extensionSchema, $schemaRaw !== '' ? $schemaRaw : 'public');
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
        $applicationSchemaFilter = $builder->hasParameter('fuzzphony.app_schema_filter')
            ? Coerce::str($builder->getParameter('fuzzphony.app_schema_filter'))
            : null;

        $services->set('fuzzphony.connection', DbalConnection::class)
            ->args([service(sprintf('doctrine.dbal.%s_connection', $connectionName))]);
        $services->alias(Connection::class, 'fuzzphony.connection');

        $services->set('fuzzphony.engine', PostgresEngine::class)
            ->args([service('fuzzphony.connection'), $names->extensionSchema, $names->schema]);
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

        $services->set('fuzzphony.introspector', PostgresIntrospector::class)->args([service('fuzzphony.connection'), $names->schema]);
        $services->alias(SourceIntrospector::class, 'fuzzphony.introspector');

        if ($ormSyncAsync) {
            if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
                // symfony/messenger is a dev dependency, so the test run can't reach this line.
                throw new InvalidConfiguration('fuzzphony.orm_sync.async requires symfony/messenger: composer require symfony/messenger'); // @codeCoverageIgnore
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
            DoctorCommand::class => [service('fuzzphony'), $applicationSchemaFilter, $names->schema],
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
