<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle;

use Doctrine\ORM\EntityManagerInterface;
use Fuzzphony\Bridge\Doctrine\DbalConnection;
use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use Fuzzphony\Bundle\Analytics\BufferedSearchLog;
use Fuzzphony\Bundle\ApiPlatform\FuzzphonySearchFilter;
use Fuzzphony\Bundle\Command\AnalyticsCommand;
use Fuzzphony\Bundle\Command\DoctorCommand;
use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Bundle\Command\SchemaCommand;
use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Bundle\Command\SearchLogPurgeCommand;
use Fuzzphony\Bundle\Command\WizardCommand;
use Fuzzphony\Bundle\Command\WorkerCommand;
use Fuzzphony\Bundle\EventListener\SearchExceptionListener;
use Fuzzphony\Bundle\EventListener\SearchLogFlushListener;
use Fuzzphony\Bundle\Messenger\MessengerRefreshDispatcher;
use Fuzzphony\Bundle\Messenger\RefreshDocumentsHandler;
use Fuzzphony\Bundle\Observability\MetricsCollectorFactory;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Bundle\Security\NullActorResolver;
use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use Fuzzphony\Bundle\Security\RateLimitGuard;
use Fuzzphony\Bundle\Security\RequestIpActorResolver;
use Fuzzphony\Bundle\Twig\SearchComponent;
use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\NullSearchLog;
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Observability\MetricsCollector;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchGate;
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
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

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
 *     actor:                       # who searches: the tenant scopes tenant-scoped indexes
 *       resolver: app.my_resolver  # a service implementing Fuzzphony\Core\Search\ActorResolver, or:
 *       tenant: account.id         # property path on the Security user (needs symfony/security-bundle + property-access)
 *       user: userIdentifier       # property path on the Security user
 *     search_log:                  # opt-in: record every search for fuzzphony:analytics
 *       enabled: false             # run fuzzphony:schema --apply after enabling
 *       retention_days: 90         # fuzzphony:search-log:purge deletes older rows
 *       redact: [email]            # "email" or regular expressions: matches are stored as [redacted]
 *       ip: off                    # off | masked | full: keep the client IP of the searcher (personal data; masked is recommended)
 *     rate_limit:                  # opt-in: limit how often a tenant or user may search
 *       limiter: search            # a framework.rate_limiter entry (service limiter.search); needs symfony/rate-limiter
 *       per: tenant                # tenant or user
 *     authorization:               # opt-in: who may search which index and which fields
 *       enabled: false             # asks isGranted(index_attribute, indexName) and isGranted(field role, indexName)
 *       index_attribute: FUZZPHONY_SEARCH  # write a voter for it, or name an existing attribute such as ROLE_USER
 *     indexes:
 *       products:              # overrides for an attribute index, or a pure YAML index
 *         thresholds: { min_score: 0.05 }
 *         profiles: { popular: { boost: 0.05, recency: 0.3 } }
 */
final class FuzzphonyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'fuzzphony';

    private const string DOCTRINE_PARAMETER = 'fuzzphony.doctrine_orm';

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
                ->arrayNode('actor')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn(array $a): bool => $a['resolver'] !== null && ($a['tenant'] !== null || $a['user'] !== null))
                        ->thenInvalid('fuzzphony.actor.resolver cannot be combined with fuzzphony.actor.tenant / fuzzphony.actor.user: the resolver decides both.')
                    ->end()
                    ->children()
                        ->scalarNode('resolver')->defaultNull()->info('Service id of your own Fuzzphony\\Core\\Search\\ActorResolver')->end()
                        ->scalarNode('tenant')->defaultNull()->info('Property path on the Security user that gives the tenant, e.g. account.id')->end()
                        ->scalarNode('user')->defaultNull()->info('Property path on the Security user that gives the user, e.g. userIdentifier')->end()
                    ->end()
                ->end()
                ->arrayNode('search_log')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Record every search (who, what, how many hits) in fuzzphony_search_log; run fuzzphony:schema --apply after enabling')->end()
                        ->integerNode('retention_days')->defaultValue(90)->min(1)->info('fuzzphony:search-log:purge deletes older rows')->end()
                        ->arrayNode('redact')->scalarPrototype()->end()->info('"email" or a regular expression with delimiters: matches are replaced by [redacted] before anything is stored')->end()
                        ->enumNode('ip')->values(['off', 'masked', 'full'])->defaultValue('off')->info('Keep the client IP (Request::getClientIp(), so framework.trusted_proxies applies) with each search: off, masked (IPv4 /24, IPv6 /48) or full; it is personal data. Run fuzzphony:schema --apply after enabling')->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('limiter')->defaultNull()->info('Name of a framework.rate_limiter entry (service limiter.<name>); empty = no rate limit')->end()
                        ->enumNode('per')->values(['tenant', 'user'])->defaultValue('tenant')->info('Whose searches share one bucket; an actor without that key is not limited')->end()
                    ->end()
                ->end()
                ->arrayNode('authorization')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('Check the index and the fields (granted: ...) with Symfony authorization before every search')->end()
                        ->scalarNode('index_attribute')->defaultValue('FUZZPHONY_SEARCH')->info('Checked with isGranted(attribute, indexName) before every search; write a voter for it, or name an existing attribute such as ROLE_USER')->end()
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
        // loadExtension() runs on a temporary builder that has no extensions registered, so it cannot ask hasExtension() itself.
        // It is told that the ORM is configured, not only DoctrineBundle: without `orm:` there is no entity manager service to wire to.
        $builder->setParameter(self::DOCTRINE_PARAMETER, array_filter($builder->getExtensionConfig('doctrine'), static fn(mixed $doctrine): bool => is_array($doctrine) && isset($doctrine['orm'])) !== []);
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

    /** @internal */
    public static function assertActorPackages(bool $security, bool $propertyAccess): void
    {
        if (!$security || !$propertyAccess) {
            throw new InvalidConfiguration('fuzzphony.actor.tenant / fuzzphony.actor.user need symfony/security-bundle and symfony/property-access: composer require symfony/security-bundle symfony/property-access');
        }
    }

    /** @internal */
    public static function assertRateLimiterPackage(bool $available): void
    {
        if (!$available) {
            throw new InvalidConfiguration('fuzzphony.rate_limit needs symfony/rate-limiter: composer require symfony/rate-limiter');
        }
    }

    /** @internal */
    public static function assertAuthorizationPackage(bool $available): void
    {
        if (!$available) {
            throw new InvalidConfiguration('fuzzphony.authorization needs symfony/security-core (symfony/security-bundle provides the authorization checker): composer require symfony/security-bundle');
        }
    }

    /** @param array<array-key, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->autowire(false)->autoconfigure(false);
        // In a real kernel $builder is a temporary builder without extensions: hasExtension() is always false there, so the
        // answer comes from the parameter prependExtension() set on the real one (hasExtension() still serves a direct load()).
        $hasOrm = interface_exists(EntityManagerInterface::class)
            && ($builder->hasExtension('doctrine') || ($builder->hasParameter(self::DOCTRINE_PARAMETER) && $builder->getParameter(self::DOCTRINE_PARAMETER) === true));

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
        $searchLog = is_array($config['search_log'] ?? null) ? $config['search_log'] : [];
        $searchLogEnabled = (bool) ($searchLog['enabled'] ?? false);
        $retentionDaysRaw = Coerce::int($searchLog['retention_days'] ?? null);
        $retentionDays = $retentionDaysRaw !== 0 ? $retentionDaysRaw : 90;
        $redact = array_values(array_map(Coerce::str(...), is_array($searchLog['redact'] ?? null) ? $searchLog['redact'] : []));
        // the address is only ever stored by the log: with the log off there is nothing to keep it for
        $ipMode = $searchLogEnabled ? (IpMode::tryFrom(Coerce::str($searchLog['ip'] ?? null)) ?? IpMode::Off) : IpMode::Off;
        if ($redact !== []) {
            // built once here so a bad pattern fails the container build (InvalidConfiguration), not the first search
            PatternQueryRedactor::fromSettings($redact);
        }
        $applicationSchemaFilter = $builder->hasParameter('fuzzphony.app_schema_filter')
            ? Coerce::str($builder->getParameter('fuzzphony.app_schema_filter'))
            : null;

        $services->set('fuzzphony.connection', DbalConnection::class)
            ->args([service(sprintf('doctrine.dbal.%s_connection', $connectionName))]);
        $services->alias(Connection::class, 'fuzzphony.connection');

        // Decided when the service is built (MetricsCollectorFactory::create()), not here at
        // loadExtension() time: CLI and FPM share this compiled container but commonly disagree
        // about apcu (apc.enable_cli=0 by default), so baking the choice into the compiled
        // definition would let whichever process warms the cache first decide it for both.
        $services->set('fuzzphony.metrics', MetricsCollector::class)
            ->factory([MetricsCollectorFactory::class, 'create'])
            ->args([service('logger')])
            // Routable/filterable as its own channel when MonologBundle is installed; a no-op tag
            // otherwise. Without it, LoggingMetricsCollector's lines are stuck in the app's default
            // channel with no way to exclude them.
            ->tag('monolog.logger', ['channel' => 'fuzzphony']);
        $services->alias(MetricsCollector::class, 'fuzzphony.metrics');

        $actor = is_array($config['actor'] ?? null) ? $config['actor'] : [];
        $actorResolver = is_string($actor['resolver'] ?? null) ? $actor['resolver'] : null;
        $tenantPath = is_string($actor['tenant'] ?? null) ? $actor['tenant'] : null;
        $userPath = is_string($actor['user'] ?? null) ? $actor['user'] : null;
        // with the client IP on, the configured resolver is wrapped: it adds the address of the request to an actor that has none
        $resolverId = $ipMode !== IpMode::Off ? 'fuzzphony.actor_resolver.inner' : 'fuzzphony.actor_resolver';
        if ($actorResolver !== null) {
            $services->alias($resolverId, $actorResolver)->public();
        } elseif ($tenantPath !== null || $userPath !== null) {
            self::assertActorPackages(interface_exists(TokenStorageInterface::class), interface_exists(PropertyAccessorInterface::class));
            $services->set($resolverId, PropertyPathActorResolver::class)
                ->args([service('security.token_storage'), service('property_accessor'), $tenantPath, $userPath])
                ->public();
        } else {
            $services->set($resolverId, NullActorResolver::class)->public();
        }
        if ($ipMode !== IpMode::Off) {
            $services->set('fuzzphony.actor_resolver', RequestIpActorResolver::class)
                ->args([service($resolverId), service('request_stack')])
                ->public();
        }
        $services->alias(ActorResolver::class, 'fuzzphony.actor_resolver')->public();

        if ($redact !== []) {
            $services->set('fuzzphony.query_redactor', PatternQueryRedactor::class)
                ->factory([PatternQueryRedactor::class, 'fromSettings'])
                ->args([$redact]);
        }
        $rateLimit = is_array($config['rate_limit'] ?? null) ? $config['rate_limit'] : [];
        $limiterName = Coerce::str($rateLimit['limiter'] ?? null);
        $authorization = is_array($config['authorization'] ?? null) ? $config['authorization'] : [];
        // Authorization first: a search that is denied must not spend a rate-limit token.
        $guards = [];
        if ((bool) ($authorization['enabled'] ?? false)) {
            self::assertAuthorizationPackage(interface_exists(AuthorizationCheckerInterface::class));
            $indexAttribute = Coerce::str($authorization['index_attribute'] ?? null);
            $services->set('fuzzphony.guard.authorization', AuthorizationGuard::class)
                ->args([service('security.authorization_checker'), service('security.token_storage'), $indexAttribute !== '' ? $indexAttribute : 'FUZZPHONY_SEARCH']);
            $guards[] = service('fuzzphony.guard.authorization');
        }
        if ($limiterName !== '') {
            self::assertRateLimiterPackage(class_exists(RateLimiterFactory::class));
            $per = Coerce::str($rateLimit['per'] ?? null);
            $services->set('fuzzphony.guard.rate_limit', RateLimitGuard::class)
                ->args([service('limiter.' . $limiterName), $per !== '' ? $per : 'tenant']);
            $guards[] = service('fuzzphony.guard.rate_limit');
        }
        $gateArguments = static fn(array $guards): array => [
            service('fuzzphony.metrics'),
            service('logger'),
            $searchLogEnabled ? service('fuzzphony.search_log') : inline_service(NullSearchLog::class),
            $searchLogEnabled && $redact !== [] ? service('fuzzphony.query_redactor') : null,
            $guards,
            $ipMode,
        ];
        $services->set('fuzzphony.search_gate', SearchGate::class)
            ->args($gateArguments($guards))
            ->tag('monolog.logger', ['channel' => 'fuzzphony']); // its log line goes to the same channel as the metrics
        $services->alias(SearchGate::class, 'fuzzphony.search_gate');
        // fuzzphony:search is an operator tool with database access: it runs no guard (a console has no Security
        // token, so authorization would deny it). Its searches are still counted and, with the log on, logged.
        $services->set('fuzzphony.search_gate.operator', SearchGate::class)
            ->args($gateArguments([]))
            ->tag('monolog.logger', ['channel' => 'fuzzphony']);
        $services->set('fuzzphony.exception_listener', SearchExceptionListener::class)->tag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => 64]);

        $services->set('fuzzphony.engine', PostgresEngine::class)
            ->args([service('fuzzphony.connection'), $names->extensionSchema, $names->schema, service('fuzzphony.metrics'), $searchLogEnabled, $retentionDays, $ipMode !== IpMode::Off]);
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
                // Prod only: discovery loads the metadata of every entity, so its result is kept in cache.system (cache:clear drops it).
                // Without kernel.debug (or when it is unknown) nothing is cached and an edited attribute shows up on the next request.
                $hasOrm && $discoverEntities && $builder->hasParameter('kernel.debug') && $builder->getParameter('kernel.debug') === false ? service('cache.system')->nullOnInvalid() : null,
            ]);
        $services->alias(IndexRegistry::class, 'fuzzphony.registry');

        $services->set('fuzzphony', Fuzzphony::class)
            ->args([service('fuzzphony.engine'), service('fuzzphony.registry'), service('fuzzphony.search_gate')]);
        $services->alias(Fuzzphony::class, 'fuzzphony')->public();
        $services->set('fuzzphony.operator', Fuzzphony::class)
            ->args([service('fuzzphony.engine'), service('fuzzphony.registry'), service('fuzzphony.search_gate.operator')]);

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
                ->args([service('fuzzphony'), service('fuzzphony.metrics')])
                ->tag('messenger.message_handler');
        }

        if (interface_exists(\ApiPlatform\Doctrine\Orm\Filter\FilterInterface::class)) {
            // #[ApiFilter(FuzzphonySearchFilter::class)] creates per-resource copies of this definition
            $services->set(FuzzphonySearchFilter::class)
                ->args([service('fuzzphony'), 'q', 'default', 500, service('fuzzphony.actor_resolver')])
                ->autowire()
                ->tag('api_platform.filter');
        }

        if (class_exists(\Symfony\UX\LiveComponent\Attribute\AsLiveComponent::class)) {
            $services->set(SearchComponent::class)
                ->args([service('fuzzphony'), service('fuzzphony.actor_resolver')])
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
            WorkerCommand::class => [service('fuzzphony'), $workerBatchSize, $workerIdleSleep, service('fuzzphony.metrics')],
            SearchCommand::class => [service('fuzzphony.operator')],
            WizardCommand::class => [service('fuzzphony.introspector'), service('fuzzphony.engine'), service('fuzzphony.connection')],
        ];
        if ($searchLogEnabled) {
            $services->set('fuzzphony.search_log', BufferedSearchLog::class)
                ->args([service_closure('fuzzphony.engine'), service('fuzzphony.metrics'), service('logger')])
                ->tag('monolog.logger', ['channel' => 'fuzzphony'])
                ->tag('kernel.reset', ['method' => 'reset']); // a worker or a long-running server flushes between messages and requests
            $services->set('fuzzphony.search_log_flush_listener', SearchLogFlushListener::class)
                ->args([service('fuzzphony.search_log')])
                ->tag('kernel.event_listener', ['event' => 'kernel.terminate'])
                ->tag('kernel.event_listener', ['event' => 'console.terminate']);
            $commands[AnalyticsCommand::class] = [service('fuzzphony')];
            $commands[SearchLogPurgeCommand::class] = [service('fuzzphony'), $retentionDays];
        }
        foreach ($commands as $class => $args) {
            $services->set($class)->args($args)->tag('console.command');
        }
    }
}
