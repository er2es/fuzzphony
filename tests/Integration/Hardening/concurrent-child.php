<?php

declare(strict_types=1);

/*
 * A second PHP process for ConcurrentApplyTest: runs one action against FUZZPHONY_TEST_DSN until it is done (or for the given
 * seconds) and prints one line of JSON: {"ok": bool, "error": string, "count": int}.
 *
 * Usage: php concurrent-child.php <apply|reindex|write|drain|sql> <sync> <schema> [seconds]   (sql: runs FZ_SQL once)
 */

use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Integration\Hardening\ConcurrentApplyTest;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $action, $sync, $schema] = $argv + [1 => '', 2 => 'queue', 3 => 'public'];
$seconds = (float) ($argv[4] ?? 0);
$dsn = (string) getenv('FUZZPHONY_TEST_DSN');
$connection = PdoConnection::fromDsn($dsn);
$index = ConcurrentApplyTest::index($sync);
$engine = new PostgresEngine($connection, 'public', $schema, searchLog: true);
$fuzzphony = new Fuzzphony\Core\Fuzzphony($engine, new Fuzzphony\Core\Registry\IndexRegistry([$index]));
$count = 0;
$base = 10000 + random_int(1, 1_000_000) * 1000;
$errors = [];
$until = microtime(true) + $seconds;
// wait for the go file, so the processes start together
$go = (string) getenv('FZ_GO_FILE');
while ($go !== '' && !is_file($go)) {
    usleep(2000);
}

try {
    do {
        try {
            switch ($action) {
                case 'apply':
                    $fuzzphony->schema()->apply($connection);
                    break;
                case 'reindex':
                    $fuzzphony->reindex('products');
                    break;
                case 'write':
                    $id = $base + $count;
                    $connection->execute("INSERT INTO fz_product VALUES (:id, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())", ['id' => $id]);
                    $connection->execute("UPDATE fz_product SET name = 'Zebrafish lamp ' || :n WHERE id = :id", ['id' => $id, 'n' => (string) $count]);
                    if ($count % 3 === 0) {
                        $connection->execute('DELETE FROM fz_product WHERE id = :id', ['id' => $id]);
                    }
                    break;
                case 'sql':
                    $connection->execute((string) getenv('FZ_SQL'));
                    break;
                case 'drain':
                    (new Worker($engine))->runOnce([$index]);
                    usleep(20000);
                    break;
                default:
                    throw new InvalidArgumentException('unknown action ' . $action);
            }
            ++$count;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $message .= ' | ' . $p->getMessage();
            }
            $errors[] = $message;
            if ($action === 'apply' || $action === 'reindex' || $action === 'sql') {
                break;
            }
            usleep(20000);
        }
    } while (microtime(true) < $until);
} finally {
    echo json_encode(['ok' => $errors === [], 'error' => implode("\n", array_slice($errors, 0, 5)), 'errors' => count($errors), 'count' => $count]), "\n";
}
