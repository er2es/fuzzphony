<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle;

use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Analytics\PatternQueryRedactor;
use Fuzzphony\Core\Support\Coerce;
use Fuzzphony\Engine\Postgres\Schema\Names;

/**
 * @internal The `fuzzphony:` configuration as the bundle wires it, typed once. The configuration tree (FuzzphonyBundle::configure())
 * guarantees these shapes; loadExtension() is public and takes a plain array, so a value that is absent or empty falls back to
 * DEFAULTS, the same table the tree takes its defaults from.
 */
final readonly class BundleSettings
{
    /** The defaults of the tree, and of a configuration that was not run through it. */
    public const array DEFAULTS = [
        'connection' => 'default',
        'extension_schema' => 'public',
        'schema' => 'public',
        'worker_batch_size' => 500,
        'worker_idle_sleep' => 1.0,
        'orm_chunk_size' => 500,
        'retention_days' => 90,
        'rate_limit_per' => 'tenant',
        'index_attribute' => 'FUZZPHONY_SEARCH',
    ];

    /**
     * @param array<array-key, mixed> $indexes     the `indexes:` overrides, validated later by the registry
     * @param list<string>            $redact
     */
    private function __construct(
        public string $connectionName,
        public Names $names,
        public bool $discoverEntities,
        public array $indexes,
        public int $workerBatchSize,
        public float $workerIdleSleep,
        public bool $ormSyncAsync,
        public int $ormSyncChunkSize,
        public bool $searchLogEnabled,
        public int $retentionDays,
        public array $redact,
        public IpMode $ipMode,
        public ?string $actorResolver,
        public ?string $tenantPath,
        public ?string $userPath,
        public string $per,
        public string $limiterName,
        public bool $authorizationEnabled,
        public string $indexAttribute,
    ) {}

    /**
     * @param array<array-key, mixed> $config
     * @param string                  $defaultIpLimiter the limiter `per: ip` uses when none is named
     */
    public static function fromConfig(array $config, string $defaultIpLimiter): self
    {
        $searchLog = self::section($config, 'search_log');
        $searchLogEnabled = (bool) ($searchLog['enabled'] ?? false);
        $redact = array_values(array_map(Coerce::str(...), is_array($searchLog['redact'] ?? null) ? $searchLog['redact'] : []));
        if ($redact !== []) {
            // built once here so a bad pattern fails the container build (InvalidConfiguration), not the first search
            PatternQueryRedactor::fromSettings($redact);
        }
        $actor = self::section($config, 'actor');
        $rateLimit = self::section($config, 'rate_limit');
        $per = self::text($rateLimit['per'] ?? null, self::DEFAULTS['rate_limit_per']);
        $limiterName = Coerce::str($rateLimit['limiter'] ?? null);
        $authorization = self::section($config, 'authorization');
        $worker = self::section($config, 'worker');
        $ormSync = self::section($config, 'orm_sync');

        return new self(
            self::text($config['connection'] ?? null, self::DEFAULTS['connection']),
            // validated here, so an invalid name fails the container build instead of the first request
            new Names(self::text($config['extension_schema'] ?? null, self::DEFAULTS['extension_schema']), self::text($config['schema'] ?? null, self::DEFAULTS['schema'])),
            (bool) ($config['discover_entities'] ?? true),
            is_array($config['indexes'] ?? null) ? $config['indexes'] : [],
            self::number($worker['batch_size'] ?? null, self::DEFAULTS['worker_batch_size']),
            self::decimal($worker['idle_sleep'] ?? null, self::DEFAULTS['worker_idle_sleep']),
            (bool) ($ormSync['async'] ?? false),
            self::number($ormSync['chunk_size'] ?? null, self::DEFAULTS['orm_chunk_size']),
            $searchLogEnabled,
            self::number($searchLog['retention_days'] ?? null, self::DEFAULTS['retention_days']),
            $redact,
            // the address is only ever stored by the log: with the log off there is nothing to keep it for
            $searchLogEnabled ? (IpMode::tryFrom(Coerce::str($searchLog['ip'] ?? null)) ?? IpMode::Off) : IpMode::Off,
            is_string($actor['resolver'] ?? null) ? $actor['resolver'] : null,
            is_string($actor['tenant'] ?? null) ? $actor['tenant'] : null,
            is_string($actor['user'] ?? null) ? $actor['user'] : null,
            $per,
            $limiterName === '' && $per === 'ip' ? $defaultIpLimiter : $limiterName,
            (bool) ($authorization['enabled'] ?? false),
            self::text($authorization['index_attribute'] ?? null, self::DEFAULTS['index_attribute']),
        );
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $name): array
    {
        return is_array($config[$name] ?? null) ? $config[$name] : [];
    }

    private static function text(mixed $value, string $default): string
    {
        $text = Coerce::str($value);

        return $text !== '' ? $text : $default;
    }

    private static function number(mixed $value, int $default): int
    {
        $number = Coerce::int($value);

        return $number !== 0 ? $number : $default;
    }

    private static function decimal(mixed $value, float $default): float
    {
        $number = Coerce::float($value);

        return $number !== 0.0 ? $number : $default;
    }
}
