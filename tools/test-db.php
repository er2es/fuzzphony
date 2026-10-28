<?php

declare(strict_types=1);

/**
 * `composer test:db [-- phpunit options]`: the whole suite against the development database of docker-compose.yml.
 *
 * FUZZPHONY_TEST_DSN wins when it is set; otherwise the DSN is built for 127.0.0.1 and FUZZPHONY_TEST_PORT (default 5432, the
 * port of `docker compose up -d`). `--exclude-group slow` is a good first run (see CONTRIBUTING.md).
 */
$dsn = getenv('FUZZPHONY_TEST_DSN');
if (!is_string($dsn) || $dsn === '') {
    $port = getenv('FUZZPHONY_TEST_PORT');
    $dsn = sprintf('pgsql:host=127.0.0.1;port=%s;dbname=fuzzphony_test;user=fuzzphony;password=fuzzphony', is_string($port) && $port !== '' ? $port : '5432');
}
putenv('FUZZPHONY_TEST_DSN=' . $dsn);

$command = array_merge([PHP_BINARY, dirname(__DIR__) . '/vendor/phpunit/phpunit/phpunit'], array_slice($argv, 1));
passthru(implode(' ', array_map('escapeshellarg', $command)), $code);

exit($code);
