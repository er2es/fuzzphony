<?php

declare(strict_types=1);

/*
 * ILIKE vs Fuzzphony on the synthetic catalogue (benchmarks/seed.sql).
 *
 *   FUZZPHONY_BENCH_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony;user=fuzzphony;password=fuzzphony" php benchmarks/run.php [--setup]
 *
 * Reports the first ("cold") run and the median of the next 5 ("warm") runs per query.
 * Output: text (default), --markdown (GitHub job summary) or --json.
 */

require $argv[0] === __FILE__ && is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : (getenv('FUZZPHONY_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php');

use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;

$dsn = getenv('FUZZPHONY_BENCH_DSN') ?: exit("Set FUZZPHONY_BENCH_DSN.\n");
$pdo = new PDO($dsn);
$connection = new PdoConnection($pdo);

$index = IndexDefinition::builder('bench')
    ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand, c.name AS category, p.price, p.in_stock, p.popularity, p.published_at
                 FROM bench_product p JOIN bench_brand b ON b.id = p.brand_id JOIN bench_category c ON c.id = p.category_id')
    ->watch('bench_product')
    ->watch('bench_brand', 'SELECT id FROM bench_product WHERE brand_id = :id')
    ->field('name', 'A', fuzzy: true)
    ->field('brand', 'B')
    ->field('category', 'C')
    ->field('description', 'D')
    ->filter('price', 'int')
    ->filter('in_stock', 'bool')
    ->boostBy('popularity')
    ->recencyBy('published_at')
    ->build();
$fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index]));

if (in_array('--setup', $argv, true) || in_array('--setup-only', $argv, true)) {
    $t = microtime(true);
    $fuzzphony->schema()->apply($connection);
    $n = $fuzzphony->reindex('bench', 10_000, static function (int $done): void { fwrite(STDERR, "\r  indexed " . number_format($done)); });
    $pdo->exec('ANALYZE fuzzphony_bench');
    fprintf(STDERR, "\nSetup: %s documents in %.1fs\n", number_format($n), microtime(true) - $t);
    if (in_array('--setup-only', $argv, true)) {
        exit(0);
    }
}

$measure = static function (callable $run): array {
    $t = hrtime(true);
    $value = $run();
    $cold = (hrtime(true) - $t) / 1e6;
    $times = [];
    for ($i = 0; $i < 5; ++$i) {
        $t = hrtime(true);
        $value = $run();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);

    return ['cold' => $cold, 'warm' => $times[2], 'value' => $value];
};

$cases = [
    'plain word' => ['wireless', '%wireless%'],
    'two words' => ['wireless mouse', '%wireless%mouse%'],
    'accent' => ['creme', '%creme%'],
    'typo' => ['hedphones', '%hedphones%'],
    'stemming' => ['drills', '%drills%'],
    'phrase + exclusion' => ['"noise cancelling" -headphones', '%noise cancelling%'],
    'filter + text' => ['kettle', '%kettle%'],
];

$rows = [];
foreach ($cases as $label => [$query, $like]) {
    $filtered = $label === 'filter + text';
    $ilike = $measure(static function () use ($pdo, $like, $filtered): int {
        $st = $pdo->prepare('SELECT p.id FROM bench_product p WHERE (p.name ILIKE :q OR p.description ILIKE :q2)' . ($filtered ? ' AND p.price < 50000 AND p.in_stock' : '') . ' LIMIT 20');
        $st->execute(['q' => $like, 'q2' => $like]);

        return count($st->fetchAll());
    });
    $fz = $measure(static function () use ($fuzzphony, $query, $filtered) {
        $search = $fuzzphony->in('bench')->query($query)->limit(20);

        return ($filtered ? $search->where('price', '<', 50_000)->where('in_stock', true) : $search)->get();
    });
    $rows[] = [
        'case' => $label,
        'query' => $query,
        'ilike_cold_ms' => round($ilike['cold'], 2),
        'ilike_warm_ms' => round($ilike['warm'], 2),
        'ilike_hits' => $ilike['value'],
        'fuzzphony_cold_ms' => round($fz['cold'], 2),
        'fuzzphony_warm_ms' => round($fz['warm'], 2),
        'fuzzphony_hits' => count($fz['value']->hits),
        'fuzzphony_total' => $fz['value']->total . ($fz['value']->totalIsLowerBound ? '+' : ''),
        'typo_tolerant' => $fz['value']->usedFuzzy,
    ];
}

$documents = (int) $pdo->query('SELECT count(*) FROM fuzzphony_bench')->fetchColumn();

if (in_array('--json', $argv, true)) {
    echo json_encode(['documents' => $documents, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(), 'php' => PHP_VERSION, 'cases' => $rows], JSON_PRETTY_PRINT), "\n";
} elseif (in_array('--markdown', $argv, true)) {
    printf("### Fuzzphony benchmark: %s documents, PostgreSQL %s, PHP %s\n\n", number_format($documents), $pdo->query('SHOW server_version')->fetchColumn(), PHP_VERSION);
    echo "| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |\n|---|---|---:|---:|---:|---:|\n";
    foreach ($rows as $r) {
        printf("| %s | `%s` | %.1f / %.1f ms | %d | %.1f / %.1f ms | %d (%s)%s |\n", $r['case'], str_replace('|', '\\|', $r['query']), $r['ilike_cold_ms'], $r['ilike_warm_ms'], $r['ilike_hits'], $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['fuzzphony_total'], $r['typo_tolerant'] ? ' ~' : '');
    }
    echo "\nWarm = median of 5 runs after the cold one. ~ = typo-tolerant fallback used. ILIKE neither ranks nor excludes.\n";
} else {
    printf("%-20s %22s %6s   %22s %6s\n", 'case', 'ILIKE cold/warm ms', 'hits', 'Fuzzphony cold/warm ms', 'hits');
    foreach ($rows as $r) {
        printf("%-20s %10.1f / %9.1f %6d   %10.1f / %9.1f %6d %s\n", $r['case'], $r['ilike_cold_ms'], $r['ilike_warm_ms'], $r['ilike_hits'], $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['typo_tolerant'] ? '~' : '');
    }
}
