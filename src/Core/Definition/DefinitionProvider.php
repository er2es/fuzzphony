<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * Supplies index definitions at runtime (a database table, a config service): the bundle autoconfigures every service that
 * implements it (tag `fuzzphony.definition_provider`); in plain PHP pass `indexes: [...$provider->definitions()]`.
 *
 * Called once each time the registry is built (bundle: once per request under FPM, once per worker or command process); the library
 * never caches the result and `cache:warmup` never calls it, so a provider that reads a slow source caches itself. A name it defines
 * must not be defined anywhere else (attribute, definition class, YAML, another provider): InvalidDefinition. Every definition is
 * validated like any other; whatever the provider throws propagates.
 *
 * A definition is code: `source.query` and `watch.affectedIds` are raw SQL run by the writer role. The validator checks their shape
 * (SELECT/WITH, identifiers), it does not sanitise them: never build a definition from text an end user or a tenant typed.
 */
interface DefinitionProvider
{
    /** @return iterable<IndexDefinition> every index this provider defines */
    public function definitions(): iterable;
}
