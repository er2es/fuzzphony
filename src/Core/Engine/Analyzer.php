<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Engine;

use Fuzzphony\Core\Definition\TextConfig;

/**
 * Turns text into index tokens. The PostgreSQL engine analyzes natively inside the
 * database (tsvector + unaccent), so it does not need one; engines without native
 * stemming / accent folding (MySQL, MariaDB) plug a PHP analyzer in here.
 */
interface Analyzer
{
    /** @return list<string> */
    public function tokens(string $text, TextConfig $config): array;
}
