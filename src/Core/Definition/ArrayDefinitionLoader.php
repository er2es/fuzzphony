<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

use Fuzzphony\Core\Exception\InvalidDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;

/**
 * Builds or overrides definitions from plain arrays (YAML config). Shape:
 *
 *   products:
 *     source: { table: product, id: id }            # or { query: "SELECT ...", id: id }
 *     id_type: int
 *     fields: { name: { weight: A, fuzzy: true }, description: C }
 *     filters: { price: int, in_stock: bool }
 *     watch: { brand: "SELECT id FROM product WHERE brand_id = :id" }
 *     sync: queue
 *     trigger_level: statement   # or row
 *     language: english
 *     unaccent: true
 *     boost: popularity
 *     recency: published_at
 *     profiles: { default: { text: 1, fuzzy: 0.5 } }
 *     thresholds: { min_score: 0.05, fuzzy_mode: fallback }
 */
final class ArrayDefinitionLoader
{
    private const array KEYS = ['source', 'id_type', 'fields', 'filters', 'watch', 'sync', 'language', 'unaccent', 'boost', 'recency', 'profiles', 'thresholds', 'class', 'trigger_level'];

    /** @param array<string, mixed> $config */
    public function load(string $name, array $config): IndexDefinition
    {
        $this->assertKnownKeys($name, $config);
        $builder = IndexDefinition::builder($name);

        /** @var array{table?: string, query?: string, id?: string} $source */
        $source = $config['source'] ?? ['table' => $name];
        isset($source['query'])
            ? $builder->fromQuery($source['query'], $source['id'] ?? 'id')
            : $builder->fromTable($source['table'] ?? $name, $source['id'] ?? 'id');

        foreach ($this->map($config['fields'] ?? []) as $field => $options) {
            $options = is_string($options) ? ['weight' => $options] : $options;
            $builder->field($field, (string) ($options['weight'] ?? 'B'), (bool) ($options['fuzzy'] ?? false), (bool) ($options['highlight'] ?? true), isset($options['column']) ? (string) $options['column'] : null);
        }
        foreach ($this->map($config['filters'] ?? []) as $filter => $options) {
            $options = is_string($options) ? ['type' => $options] : $options;
            $builder->filter($filter, (string) ($options['type'] ?? ''), isset($options['column']) ? (string) $options['column'] : null);
        }
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $options;
            $builder->watch($table, (string) ($options['ids'] ?? 'SELECT :id'), (string) ($options['key'] ?? 'id'));
        }

        $this->apply($builder, $config);
        if (isset($config['class']) && is_string($config['class']) && class_exists($config['class'])) {
            $builder->entity($config['class']);
        }

        return $builder->build();
    }

    /**
     * Applies YAML overrides on top of an attribute-based definition (attributes stay primary).
     *
     * @param array<string, mixed> $config
     */
    public function override(IndexDefinition $definition, array $config): IndexDefinition
    {
        $this->assertKnownKeys($definition->name, $config);
        $changes = [];
        if (isset($config['sync'])) {
            $changes['sync'] = SyncMode::from((string) $config['sync']);
        }
        if (isset($config['trigger_level'])) {
            $changes['triggerLevel'] = TriggerLevel::from((string) $config['trigger_level']);
        }
        if (isset($config['language']) || isset($config['unaccent'])) {
            $changes['text'] = new TextConfig((string) ($config['language'] ?? $definition->text->language), (bool) ($config['unaccent'] ?? $definition->text->unaccent));
        }
        if (isset($config['boost'])) {
            $changes['boostColumn'] = (string) $config['boost'];
        }
        if (isset($config['recency'])) {
            $changes['recencyColumn'] = (string) $config['recency'];
        }
        if (isset($config['profiles'])) {
            $changes['profiles'] = $this->profiles($config['profiles']) + $definition->profiles;
        }
        if (isset($config['thresholds'])) {
            $changes['thresholds'] = $definition->thresholds->with($this->map($config['thresholds']));
        }
        foreach ($this->map($config['watch'] ?? []) as $table => $options) {
            $options = is_string($options) ? ['ids' => $options] : $options;
            $changes['watches'] = [...($changes['watches'] ?? $definition->watches), new Watch($table, (string) ($options['ids'] ?? 'SELECT :id'), (string) ($options['key'] ?? 'id'))];
        }

        $merged = $definition->with(...$changes);
        DefinitionValidator::assertValid($merged);

        return $merged;
    }

    /** @param array<string, mixed> $config */
    private function apply(IndexBuilder $builder, array $config): void
    {
        if (isset($config['id_type'])) {
            $builder->idType((string) $config['id_type']);
        }
        if (isset($config['sync'])) {
            $builder->sync((string) $config['sync']);
        }
        if (isset($config['trigger_level'])) {
            $builder->triggerLevel((string) $config['trigger_level']);
        }
        if (isset($config['language']) || isset($config['unaccent'])) {
            $builder->language((string) ($config['language'] ?? 'english'), (bool) ($config['unaccent'] ?? true));
        }
        if (isset($config['boost'])) {
            $builder->boostBy((string) $config['boost']);
        }
        if (isset($config['recency'])) {
            $builder->recencyBy((string) $config['recency']);
        }
        foreach ($this->profiles($config['profiles'] ?? []) as $profileName => $profile) {
            $builder->profile($profileName, $profile);
        }
        if (isset($config['thresholds'])) {
            $builder->thresholds((new \Fuzzphony\Core\Ranking\Thresholds())->with($this->map($config['thresholds'])));
        }
    }

    /** @return array<string, RankingProfile> */
    private function profiles(mixed $config): array
    {
        $profiles = [];
        foreach ($this->map($config) as $name => $options) {
            $profiles[$name] = RankingProfile::fromArray(is_array($options) ? $options : []);
        }

        return $profiles;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $config */
    private function assertKnownKeys(string $name, array $config): void
    {
        $unknown = array_diff(array_keys($config), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidDefinition($name, [sprintf('Unknown option(s): %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::KEYS))]);
        }
    }
}
