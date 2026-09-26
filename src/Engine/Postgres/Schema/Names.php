<?php

declare(strict_types=1);

namespace Fuzzphony\Engine\Postgres\Schema;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Exception\InvalidConfiguration;
use Fuzzphony\Core\Support\Identifier;
use Fuzzphony\Engine\Postgres\Sql\Sql;

/**
 * @internal Every database object name Fuzzphony creates, in one place. Methods ending in "Name"
 * return the bare name (catalog lookups, derived index and trigger names); the others return SQL.
 * Index and trigger names are never qualified: an index lives in its table's schema, a trigger on
 * its table.
 */
final readonly class Names
{
    /** PostgreSQL's identifier limit (NAMEDATALEN - 1). */
    public const int MAX_IDENTIFIER_BYTES = 63;
    private const string PREFIX = 'fuzzphony_';

    public function __construct(
        /** Schema of the pg_trgm and unaccent extensions. */
        public string $extensionSchema = 'public',
    ) {
        if (!Identifier::isColumn($extensionSchema)) {
            throw new InvalidConfiguration(sprintf('Invalid extension schema "%s".', $extensionSchema));
        }
    }

    /** Keeps a generated name within PostgreSQL's limit while staying unique. */
    public static function limit(string $name, int $max = self::MAX_IDENTIFIER_BYTES): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 9) . '_' . hash('crc32b', $name);
    }

    public function extension(): string
    {
        return Sql::ident($this->extensionSchema);
    }

    public function sidecarName(IndexDefinition $index): string
    {
        return self::PREFIX . $index->name;
    }

    public function sidecar(IndexDefinition $index): string
    {
        return $this->qualify($this->sidecarName($index));
    }

    public function queue(): string
    {
        return self::PREFIX . 'queue';
    }

    public function queueOrderIndex(): string
    {
        return self::PREFIX . 'queue_order';
    }

    public function normFunction(): string
    {
        return self::PREFIX . 'norm';
    }

    public function refreshFunctionName(IndexDefinition $index): string
    {
        return self::limit(self::PREFIX . 'refresh_' . $index->name);
    }

    public function refreshFunction(IndexDefinition $index): string
    {
        return $this->qualify($this->refreshFunctionName($index));
    }

    public function syncFunctionName(IndexDefinition $index, Watch $watch): string
    {
        return self::limit(self::PREFIX . 'sync_' . $index->name . '__' . str_replace('.', '_', $watch->table));
    }

    public function syncFunction(IndexDefinition $index, Watch $watch): string
    {
        return $this->qualify($this->syncFunctionName($index, $watch));
    }

    /** "" = the row-level trigger (named like its function); "_ins", "_upd", "_del", "_trn" = the others. */
    public function triggerName(IndexDefinition $index, Watch $watch, string $suffix = ''): string
    {
        $function = $this->syncFunctionName($index, $watch);

        return $suffix === '' ? $function : self::limit($function . $suffix);
    }

    public function indexName(IndexDefinition $index, string $suffix): string
    {
        return self::limit($this->sidecarName($index) . '_' . $suffix);
    }

    /** The configuration an index uses: Fuzzphony's accent-folding copy, or the built-in one. */
    public function textConfigName(TextConfig $config): string
    {
        return $config->unaccent ? self::PREFIX . $config->language : $config->language;
    }

    public function textConfig(TextConfig $config): string
    {
        return $config->unaccent ? $this->qualify($this->textConfigName($config)) : Sql::ident($config->language);
    }

    /** The configuration as a SQL value, for to_tsvector() / to_tsquery() / ts_headline(). */
    public function regconfig(TextConfig $config): string
    {
        return Sql::string($this->textConfigName($config)) . '::regconfig';
    }

    /** The dictionary the accent-folding configuration consults for stop words, before unaccent. */
    public function stopDictionaryName(TextConfig $config): string
    {
        return self::PREFIX . $config->language . '_stop';
    }

    public function stopDictionary(TextConfig $config): string
    {
        return $this->qualify($this->stopDictionaryName($config));
    }

    private function qualify(string $name): string
    {
        return Sql::ident($name);
    }
}
