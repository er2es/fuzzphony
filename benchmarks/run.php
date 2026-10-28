<?php

declare(strict_types=1);

/*
 * ILIKE vs Fuzzphony on the synthetic catalogue (benchmarks/seed.sql).
 *
 *   FUZZPHONY_BENCH_DSN="pgsql:host=127.0.0.1;dbname=fuzzphony_bench;user=fuzzphony;password=fuzzphony" php benchmarks/run.php [--setup]
 *
 * Reports the first ("cold") run and the median of the next 5 ("warm") runs per query: ILIKE against Fuzzphony for the common cases,
 * then Fuzzphony alone for the paths that cost more than a plain word (a filter with a disjunctive facet, a restricted viewer, a query of
 * sixteen mistyped words, a deep page, a long typo-tolerant text, a restricted viewer on an index with granted fields whose hidden text is
 * full of the words searched), see docs/benchmarks.md.
 * Output: text (default), --markdown (GitHub job summary) or --json.
 */

require $argv[0] === __FILE__ && is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : (getenv('FUZZPHONY_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php');

use Fuzzphony\Core\Database\PdoConnection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\ReindexOptions;
use Fuzzphony\Engine\Postgres\PostgresEngine;

$dsn = getenv('FUZZPHONY_BENCH_DSN') ?: exit("Set FUZZPHONY_BENCH_DSN.\n");

// Refuses to run against a database that isn't clearly disposable: the benchmark drops and
// reseeds the bench_* tables (see benchmarks/seed.sql). Case-insensitive substring check, same
// rule as the integration tests' guard (PostgresTestCase::assertDisposable()).
preg_match('/dbname=([^;]+)/', $dsn, $match);
$dbName = $match[1] ?? '';
if (stripos($dbName, 'bench') === false && stripos($dbName, 'test') === false) {
    exit(sprintf(
        "Refusing to run the benchmark against database \"%s\": it drops and reseeds the bench_* tables; use a database whose name contains \"bench\".\n",
        $dbName,
    ));
}

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
// Four more typo-tolerant copies of the description: the wide, long typo-tolerant text of a real catalogue (about 300 characters of it per
// document), where every typo match is rechecked on more text. Built by --setup next to the first index.
$long = IndexDefinition::builder('bench_long')
    ->fromQuery("SELECT p.id, p.name, p.description, b.name AS brand, c.name AS category, p.description || ' ' || p.name AS d1, p.description AS d2, p.description AS d3, p.description AS d4, p.price, p.in_stock
                 FROM bench_product p JOIN bench_brand b ON b.id = p.brand_id JOIN bench_category c ON c.id = p.category_id")
    ->watch('bench_product')
    ->field('name', 'A', fuzzy: true)
    ->field('brand', 'B')
    ->field('category', 'C')
    ->field('description', 'D', fuzzy: true)
    ->field('d1', 'D', fuzzy: true)
    ->field('d2', 'D', fuzzy: true)
    ->field('d3', 'D', fuzzy: true)
    ->field('d4', 'D', fuzzy: true)
    ->filter('price', 'int')
    ->filter('in_stock', 'bool')
    ->build();
// Brand and description are granted (the viewer who holds neither is the common restricted one); name and category are the open class, with
// their own two indexes (OpenClass). Every field typo-tolerant, the description full of very common words (battery, ideal, office, gaming).
$granted = IndexDefinition::builder('bench_granted')
    ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand, c.name AS category, p.price, p.in_stock FROM bench_product p JOIN bench_brand b ON b.id = p.brand_id JOIN bench_category c ON c.id = p.category_id')
    ->watch('bench_product')
    ->field('name', 'A', fuzzy: true)
    ->field('brand', 'B', fuzzy: true, granted: 'ROLE_BRAND')
    ->field('category', 'C', fuzzy: true)
    ->field('description', 'D', fuzzy: true, granted: 'ROLE_DESCRIPTION')
    ->filter('price', 'int')
    ->filter('in_stock', 'bool')
    ->build();
$fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([$index, $long, $granted]));

if (in_array('--setup', $argv, true) || in_array('--setup-only', $argv, true)) {
    $t = microtime(true);
    $fuzzphony->schema()->apply($connection);
    $n = $fuzzphony->reindex('bench', new ReindexOptions(batchSize: 10_000, onBatch: static function (int $done): void { fwrite(STDERR, "\r  indexed " . number_format($done)); }))->written;
    $fuzzphony->reindex('bench_long', new ReindexOptions(batchSize: 10_000));
    $fuzzphony->reindex('bench_granted', new ReindexOptions(batchSize: 10_000));
    $pdo->exec('ANALYZE fuzzphony_bench');
    $pdo->exec('ANALYZE fuzzphony_bench_long');
    $pdo->exec('ANALYZE fuzzphony_bench_granted');
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

// The paths that cost more than a plain word. Fuzzphony only: ILIKE can neither rank, nor page past what it scans, nor facet.
$paths = [
    'typo + filter + facet' => ['bench', 'hedphones', static fn($s) => $s->where('in_stock', true)->facets('in_stock')],
    'typo, restricted viewer' => ['bench', 'hedphones', static fn($s) => $s->restrictedToFields('name', 'brand')],
    'typo, 16 words OR' => ['bench', 'hedphones OR kettel OR profesional OR mosue OR wireles OR keybord OR moniter OR speker OR batery OR cabel OR chargr OR laptp OR tablt OR printr OR scaner OR webcm', static fn($s) => $s],
    'typo, a page past the limit' => ['bench', 'hedphones', static fn($s) => $s->limit(20, 2000)],
    'strict, a page past the limit' => ['bench', 'wireless', static fn($s) => $s->limit(20, 2000)],
    'typo, long typo-tolerant text' => ['bench_long', 'batery', static fn($s) => $s],
    'typo, long text, restricted' => ['bench_long', 'batery', static fn($s) => $s->restrictedToFields('name', 'description')],
    'exact word beside a typo' => ['bench', 'mousse', static fn($s) => $s],
    // a viewer who holds neither granted field (name, category): the words the hidden fields are full of, through the open-class indexes
    'hidden-heavy, 1 word' => ['bench_granted', 'battery', static fn($s) => $s->restrictedToFields('name', 'category')],
    'hidden-heavy, 3 words' => ['bench_granted', 'battery ideal office', static fn($s) => $s->restrictedToFields('name', 'category')],
    'hidden-heavy, 5 words' => ['bench_granted', 'battery ideal office gaming travel', static fn($s) => $s->restrictedToFields('name', 'category')],
    'hidden-heavy, phrase' => ['bench_granted', '"battery life"', static fn($s) => $s->restrictedToFields('name', 'category')],
    'hidden-heavy, rare word' => ['bench_granted', 'lenvo', static fn($s) => $s->restrictedToFields('name', 'category')],
    'hidden-heavy, typo + hidden word' => ['bench_granted', 'batery ideal', static fn($s) => $s->restrictedToFields('name', 'category')],
    // viewers who hold a granted field keep fz and tsv: the one that holds the big granted field and hides a small one must not get slower
    'granted text held, brand hidden' => ['bench_granted', 'keyb*', static fn($s) => $s->restrictedToFields('name', 'category', 'description')],
    'brand held, description hidden' => ['bench_granted', 'battery ideal office', static fn($s) => $s->restrictedToFields('name', 'category', 'brand')],
];
$pathRows = [];
$longReady = $pdo->query("SELECT to_regclass('fuzzphony_bench_long') IS NOT NULL")->fetchColumn();
foreach ($paths as $label => [$name, $query, $configure]) {
    if ($name === 'bench_granted' && !$pdo->query("SELECT to_regclass('fuzzphony_bench_granted') IS NOT NULL")->fetchColumn()) {
        continue; // a database set up before this index existed: run with --setup
    }
    if ($name === 'bench_long' && !$longReady) {
        continue; // a database set up before this index existed: run with --setup
    }
    $fz = $measure(static function () use ($fuzzphony, $name, $query, $configure) {
        return $configure($fuzzphony->in($name)->query($query)->limit(20))->get();
    });
    $pathRows[] = [
        'case' => $label,
        'query' => strlen($query) > 40 ? substr($query, 0, 37) . '...' : $query,
        'fuzzphony_cold_ms' => round($fz['cold'], 2),
        'fuzzphony_warm_ms' => round($fz['warm'], 2),
        'fuzzphony_hits' => count($fz['value']->hits),
        'fuzzphony_total' => $fz['value']->total . ($fz['value']->totalIsLowerBound ? '+' : ''),
        'typo_tolerant' => $fz['value']->usedFuzzy,
    ];
}

$documents = (int) $pdo->query('SELECT count(*) FROM fuzzphony_bench')->fetchColumn();

if (in_array('--json', $argv, true)) {
    echo json_encode(['documents' => $documents, 'postgres' => $pdo->query('SHOW server_version')->fetchColumn(), 'php' => PHP_VERSION, 'cases' => $rows, 'paths' => $pathRows], JSON_PRETTY_PRINT), "\n";
} elseif (in_array('--markdown', $argv, true)) {
    printf("### Fuzzphony benchmark: %s documents, PostgreSQL %s, PHP %s\n\n", number_format($documents), $pdo->query('SHOW server_version')->fetchColumn(), PHP_VERSION);
    echo "| case | query | ILIKE cold / warm | hits | Fuzzphony cold / warm | hits (total) |\n|---|---|---:|---:|---:|---:|\n";
    foreach ($rows as $r) {
        printf("| %s | `%s` | %.1f / %.1f ms | %d | %.1f / %.1f ms | %d (%s)%s |\n", $r['case'], str_replace('|', '\\|', $r['query']), $r['ilike_cold_ms'], $r['ilike_warm_ms'], $r['ilike_hits'], $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['fuzzphony_total'], $r['typo_tolerant'] ? ' ~' : '');
    }
    echo "\nWarm = median of 5 runs after the cold one. ~ = typo-tolerant fallback used. ILIKE neither ranks nor excludes.\n";
    echo "\n| path | query | Fuzzphony cold / warm | hits (total) |\n|---|---|---:|---:|\n";
    foreach ($pathRows as $r) {
        printf("| %s | `%s` | %.1f / %.1f ms | %d (%s)%s |\n", $r['case'], str_replace('|', '\\|', $r['query']), $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['fuzzphony_total'], $r['typo_tolerant'] ? ' ~' : '');
    }
} else {
    printf("%-20s %22s %6s   %22s %6s\n", 'case', 'ILIKE cold/warm ms', 'hits', 'Fuzzphony cold/warm ms', 'hits');
    foreach ($rows as $r) {
        printf("%-20s %10.1f / %9.1f %6d   %10.1f / %9.1f %6d %s\n", $r['case'], $r['ilike_cold_ms'], $r['ilike_warm_ms'], $r['ilike_hits'], $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['typo_tolerant'] ? '~' : '');
    }
    printf("\n%-30s %26s %6s\n", 'path', 'Fuzzphony cold/warm ms', 'hits');
    foreach ($pathRows as $r) {
        printf("%-30s %12.1f / %9.1f %6d %s\n", $r['case'], $r['fuzzphony_cold_ms'], $r['fuzzphony_warm_ms'], $r['fuzzphony_hits'], $r['typo_tolerant'] ? '~' : '');
    }
}
