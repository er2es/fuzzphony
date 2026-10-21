<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Docs;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Fuzzphony\Bridge\Doctrine\DoctrineNamingStrategy;
use Fuzzphony\Bridge\Doctrine\EntityLoader;
use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\ArrayDefinitionLoader;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Core\Wizard\DefinitionSuggester;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Engine\Postgres\Wizard\PostgresIntrospector;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/migrating-from-like.md: every php and yaml snippet of the guide is run here, exactly as the guide
 * shows it (testGuideShowsTheSnippetsThisTestRuns), and the claims of the guide are asserted on a small
 * fixture table: what ILIKE misses, what each Fuzzphony query finds, the order of the ids, the totals.
 */
final class LikeGuideTest extends TestCase
{
    private const string PRODUCT = 'App\Entity\Product';
    private const string GUIDE = __DIR__ . '/../../../docs/migrating-from-like.md';

    /** @var array<string, true> */
    private static array $declared = [];

    private Connection $connection;
    private EntityManagerInterface $em;
    private Fuzzphony $fuzzphony;
    private IndexDefinition $definition;

    /** @return array<string, string> name => the code the guide shows */
    private static function snippets(): array
    {
        return [
            'entity' => <<<'PHP'
                namespace App\Entity;

                use Doctrine\ORM\Mapping as ORM;
                use Fuzzphony\Core\Attribute\{Searchable, SearchField, SearchFilter};
                use Fuzzphony\Core\Definition\SyncMode;

                #[ORM\Entity]
                #[Searchable(sync: SyncMode::Trigger, boost: 'popularity', recency: 'published_at')]
                class Product
                {
                    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
                    #[ORM\Column, SearchField('A', fuzzy: true)] public string $name = '';
                    #[ORM\Column(type: 'text'), SearchField('C')] public string $description = '';
                    #[ORM\Column, SearchFilter] public string $category = '';
                    #[ORM\Column, SearchFilter] public int $price = 0;
                    #[ORM\Column, SearchFilter] public bool $inStock = true;
                    #[ORM\Column] public float $popularity = 0.0;   // 0 to 1: it is multiplied by the profile's boost
                    #[ORM\Column] public \DateTimeImmutable $publishedAt;
                }
                PHP,
            'before' => <<<'PHP'
                use App\Entity\Product;

                $products = $em->createQueryBuilder()
                    ->select('p')->from(Product::class, 'p')
                    ->where('p.inStock = true')
                    ->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.description) LIKE :q')
                    ->setParameter('q', '%' . mb_strtolower($text) . '%')
                    ->orderBy('p.popularity', 'DESC')
                    ->setMaxResults(20)
                    ->getQuery()
                    ->getResult();
                PHP,
            'profiles-yaml' => <<<'YAML'
                fuzzphony:
                  indexes:
                    products:
                      profiles:
                        popular: { boost: 1.0, recency: 0.2 }
                YAML,
            'build' => <<<'PHP'
                $fuzzphony->schema()->apply($connection);   // bin/console fuzzphony:schema --apply
                $fuzzphony->reindex('products');            // bin/console fuzzphony:reindex
                PHP,
            'search' => <<<'PHP'
                namespace App\Search;

                use App\Entity\Product;
                use Fuzzphony\Bridge\Doctrine\EntityLoader;
                use Fuzzphony\Core\Fuzzphony;

                final readonly class ProductSearch
                {
                    public function __construct(private Fuzzphony $fuzzphony, private EntityLoader $loader) {}

                    /** @param list<string> $categories */
                    public function __invoke(string $text, ?int $maxPrice = null, array $categories = [], int $page = 1): array
                    {
                        $search = $this->fuzzphony->in(Product::class)
                            ->query($text)
                            ->where('in_stock', true)
                            ->profile('popular')
                            ->page($page, perPage: 20);
                        if ($maxPrice !== null) {
                            $search = $search->where('price', '<=', $maxPrice);
                        }
                        if ($categories !== []) {
                            $search = $search->whereIn('category', $categories);
                        }
                        $result = $search->get();

                        return [
                            'products' => array_column($this->loader->load($result, Product::class), 'entity'),
                            'total' => $result->total,
                            'more' => $result->hasMore(),
                        ];
                    }
                }
                PHP,
            'by-ids' => <<<'PHP'
                use App\Entity\Product;

                $ids = $result->ids();                                      // best match first
                $byId = [];
                foreach ($em->getRepository(Product::class)->findBy(['id' => $ids]) as $product) {
                    $byId[$product->id] = $product;                         // the database returns them in its own order
                }
                $products = [];
                foreach ($ids as $id) {                                     // so walk the ids, not the rows
                    if (isset($byId[$id])) {                                // a row deleted since the last sync is skipped
                        $products[] = $byId[$id];
                    }
                }
                PHP,
            'pagination' => <<<'PHP'
                $result = $fuzzphony->in('products')->query($text)->page($page, perPage: 20)->get();

                $total = $result->totalIsLowerBound ? $result->total . '+' : (string) $result->total;   // "2000+" or "37"
                $label = ($result->offset + 1) . '-' . ($result->offset + count($result->hits)) . ' of ' . $total;
                $hasNextPage = $result->hasMore();
                PHP,
            'exact' => <<<'PHP'
                $result = $fuzzphony->in('products')
                    ->query($text)
                    ->thresholds(['fuzzy_mode' => 'never'])   // no typo tolerance: only the words that are really there
                    ->get();
                PHP,
            'joined-yaml' => <<<'YAML'
                fuzzphony:
                  indexes:
                    products:
                      source:
                        query: |
                          SELECT p.id, p.name, p.description, s.name AS supplier, p.category, p.price, p.in_stock
                          FROM product p LEFT JOIN supplier s ON s.id = p.supplier_id
                      fields: { name: { weight: A, fuzzy: true }, supplier: B, description: C }
                      filters: { category: string, price: int, in_stock: bool }
                      watch:
                        product: 'SELECT :id'
                        supplier: 'SELECT id FROM product WHERE supplier_id = :id'
                      sync: trigger
                YAML,
            'shadow' => <<<'PHP'
                namespace App\Search;

                use Doctrine\ORM\EntityManagerInterface;
                use Fuzzphony\Core\Fuzzphony;
                use Psr\Log\LoggerInterface;

                final readonly class SearchRollout
                {
                    public function __construct(
                        private Fuzzphony $fuzzphony,
                        private EntityManagerInterface $em,
                        private LoggerInterface $logger,
                        private bool $serveFuzzphony,   // the feature flag: a parameter, an env var, your flag service
                        private bool $shadow = true,    // also run the other search, and log what differs
                    ) {}

                    /** @return list<int> product ids, best first */
                    public function ids(string $text): array
                    {
                        $like = fn(): array => $this->em->createQuery('SELECT p.id FROM App\Entity\Product p WHERE p.inStock = true AND LOWER(p.name) LIKE :q ORDER BY p.popularity DESC')
                            ->setParameter('q', '%' . mb_strtolower($text) . '%')->setMaxResults(20)->getSingleColumnResult();
                        $fuzzy = fn(): array => $this->fuzzphony->in('products')->query($text)->where('in_stock', true)->profile('popular')->limit(20)->get()->ids();
                        [$served, $other] = $this->serveFuzzphony ? [$fuzzy, $like] : [$like, $fuzzy];

                        $ids = $served();
                        if ($this->shadow) {
                            try {
                                $others = $other();
                                $this->logger->info('search shadow', ['q' => $text, 'only_served' => array_values(array_diff($ids, $others)), 'only_other' => array_values(array_diff($others, $ids))]);
                            } catch (\Throwable $e) {
                                $this->logger->warning('search shadow failed', ['q' => $text, 'error' => $e->getMessage()]);
                            }
                        }

                        return $ids;
                    }
                }
                PHP,
            'explain' => <<<'PHP'
                $explanation = $fuzzphony->in('products')->query($text)->where('in_stock', true)->explain();   // explain(analyze: true) runs it too

                echo $explanation->interpretedAs, "\n";             // how the text was understood
                foreach ($explanation->statements as $statement) {   // the SQL it sent
                    echo $statement['label'], "\n", $statement['sql'], "\n";
                }
                echo implode("\n", $explanation->plan), "\n";        // PostgreSQL's plan
                echo $explanation->result->tookMs, " ms\n";
                PHP,
        ];
    }

    /**
     * The mapping table of the guide. Key: the Fuzzphony text. `like`: the predicate of the first column, run
     * as it stands; `likeNames`: what it finds in `name`; `names`: what the Fuzzphony text finds; `first`: only
     * the names it must put first (the rest are weaker look-alikes); `never`: searched with fuzzy_mode never.
     *
     * @return array<string, array{like: string, likeNames: list<string>, names?: list<string>, first?: list<string>, never?: true}>
     */
    private static function mapping(): array
    {
        return [
            'mouse' => [
                'like' => "name ILIKE '%mouse%'",
                'likeNames' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad'],
                'names' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad', 'USB receiver'],
            ],
            'head*' => [
                'like' => "name ILIKE 'head%'",
                'likeNames' => ['Headphone stand'],
                'names' => ['Headphone stand', 'Wireless noise cancelling headphones', 'Wired headphones'],
                'first' => ['Headphone stand'],
            ],
            '*phones' => [
                'like' => "name ILIKE '%phones'",
                'likeNames' => ['Wireless noise cancelling headphones', 'Wired headphones', 'Earphones'],
                'names' => ['Phone case'],
                'never' => true,
            ],
            'wireless mouse' => [
                'like' => "name ILIKE '%wireless%' AND name ILIKE '%mouse%'",
                'likeNames' => ['Wireless mouse', 'Wireless mouse pad'],
                'names' => ['Wireless mouse', 'Wireless mouse pad', 'USB receiver'],
            ],
            'wire* mouse' => [
                'like' => "name ILIKE 'wire%mouse'",
                'likeNames' => ['Wireless mouse'],
                'names' => ['Wireless mouse', 'Wireless mouse pad', 'USB receiver'],
            ],
            '"noise cancelling"' => [
                'like' => "name ILIKE '%noise cancelling%'",
                'likeNames' => ['Wireless noise cancelling headphones'],
                'names' => ['Wireless noise cancelling headphones'],
            ],
            '"wireless mouse"' => [
                'like' => "name ILIKE 'wireless mouse'",
                'likeNames' => ['Wireless mouse'],
                'names' => ['Wireless mouse', 'Wireless mouse pad', 'USB receiver'],
                'first' => ['Wireless mouse'],
                'never' => true,
            ],
            'mouse OR keyboard' => [
                'like' => "name ILIKE '%mouse%' OR name ILIKE '%keyboard%'",
                'likeNames' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad', 'Mechanical keyboard', 'Keyboard cover'],
                'names' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad', 'USB receiver', 'Mechanical keyboard', 'Keyboard cover'],
            ],
            'headphones -wireless' => [
                'like' => "name ILIKE '%headphones%' AND name NOT ILIKE '%wireless%'",
                'likeNames' => ['Wired headphones'],
                'first' => ['Wired headphones', 'Headphone stand'],
            ],
            'name:mouse' => [
                'like' => "name ILIKE '%mouse%'",
                'likeNames' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad'],
                'names' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad'],
            ],
            'MOUSE' => [
                'like' => "name LIKE '%MOUSE%'",
                'likeNames' => [],
                'names' => ['Mouse trap', 'Wireless mouse', 'Ergonomic mouse pad', 'Wireless mouse pad', 'USB receiver'],
            ],
            'creme' => [
                'like' => "name ILIKE '%creme%'",
                'likeNames' => [],
                'names' => ['Crème brûlée torch'],
            ],
            'hedphones' => [
                'like' => "name ILIKE '%hedphones%'",
                'likeNames' => [],
                'first' => ['Wireless noise cancelling headphones', 'Wired headphones'],
            ],
            'drills' => [
                'like' => "name ILIKE '%drills%'",
                'likeNames' => [],
                'names' => ['Cordless drill', 'Drill bit set'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            self::drop($this->connection);
        }
    }

    private static function drop(Connection $connection): void
    {
        $connection->execute('DROP TABLE IF EXISTS product, supplier, fuzzphony_products, fuzzphony_products__vocab, fuzzphony_products__next, fuzzphony_products__changes, fuzzphony_queue, fuzzphony_meta CASCADE');
    }

    private static function snippet(string $name): string
    {
        return self::snippets()[$name] ?? throw new \LogicException('No snippet ' . $name);
    }

    /**
     * Runs a snippet as the guide shows it. A snippet that declares a class is declared once per process.
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed> the variables the snippet leaves behind
     */
    private static function runSnippet(string $name, array $vars = []): array
    {
        $code = self::snippet($name);
        if (str_starts_with($code, 'namespace ')) {
            if (isset(self::$declared[$name])) {
                return [];
            }
            self::$declared[$name] = true;
        }

        return (static function (string $code, array $vars): array {
            extract($vars);
            eval($code);
            unset($code, $vars);

            return get_defined_vars();
        })($code, $vars);
    }

    private static function declare(string $name): void
    {
        self::runSnippet($name);
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $values
     */
    private static function make(string $class, array $values): object
    {
        $reflection = new \ReflectionClass($class);
        $object = $reflection->newInstance();
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($object, $value);
        }

        return $object;
    }

    private function boot(SyncMode $sync = SyncMode::Trigger, ?Thresholds $thresholds = null, int $cables = 0): void
    {
        $this->connection = PostgresTestCase::connect();
        self::drop($this->connection);
        self::declare('entity');
        $this->em = self::entityManager();
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(self::productClass())]);

        // name, description, category, price, in stock, popularity
        $rows = [
            ['Wireless mouse', 'Silent click, 2.4 GHz', 'office', 2500, true, 0.40],
            ['Wireless noise cancelling headphones', 'Over-ear, 30 h battery', 'audio', 18000, true, 0.90],
            ['Wired headphones', 'Studio monitor', 'audio', 6000, true, 0.20],
            ['Headphone stand', 'Aluminium', 'audio', 3000, true, 0.05],
            ['Crème brûlée torch', 'Kitchen torch', 'kitchen', 4500, true, 0.10],
            ['Cordless drill', '18 V with two batteries', 'tools', 12000, true, 0.60],
            ['Drill bit set', '25 pieces', 'tools', 2000, false, 0.30],
            ['Mechanical keyboard', 'Blue switches', 'office', 9000, true, 0.50],
            ['Keyboard cover', 'Silicone', 'office', 500, true, 0.01],
            ['Ergonomic mouse pad', 'Gel wrist rest', 'office', 1500, true, 0.15],
            ['Smartphones tripod', 'Flexible legs', 'phone', 2200, true, 0.03],
            ['Phone case', 'Fits most phones', 'phone', 900, true, 0.02],
            ['Earphones', 'In-ear, with a microphone', 'audio', 2900, true, 0.08],
            ['Mouse trap', 'Catches a mouse alive', 'home', 800, true, 0.04],
            ['Wireless mouse pad', 'Charges a wireless mouse', 'office', 3500, true, 0.25],
            ['USB receiver', 'Spare receiver for a wireless mouse', 'office', 900, true, 0.06],
        ];
        for ($i = 1; $i <= $cables; ++$i) {
            $rows[] = ['Cable ' . $i, 'A cable', 'office', 100 * $i, true, 0.01];
        }
        foreach ($rows as [$name, $description, $category, $price, $inStock, $popularity]) {
            $this->em->persist(self::make(self::productClass(), [
                'name' => $name, 'description' => $description, 'category' => $category, 'price' => $price,
                'inStock' => $inStock, 'popularity' => $popularity, 'publishedAt' => new \DateTimeImmutable('2026-01-01'),
            ]));
        }
        $this->em->flush();

        // the entity's attributes, plus the profile the guide puts in YAML
        $definition = (new AttributeDefinitionLoader(new DoctrineNamingStrategy($this->em)))->load(self::productClass());
        $yaml = Yaml::parse(self::snippet('profiles-yaml'));
        $definition = (new ArrayDefinitionLoader())->override($definition, self::indexConfig($yaml));
        $definition = $definition->withSync($sync);
        $this->definition = $thresholds === null ? $definition : $definition->withThresholds($thresholds);
        $this->fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$this->definition]));

        self::runSnippet('build', ['fuzzphony' => $this->fuzzphony, 'connection' => $this->connection]);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());   // Symfony's default
        $config->enableNativeLazyObjects(true);

        return new \Doctrine\ORM\EntityManager(DoctrineTestCase::dbalConnection(), $config);
    }

    /** @return array<string, mixed> */
    private static function indexConfig(mixed $yaml): array
    {
        self::assertIsArray($yaml);
        $fuzzphony = $yaml['fuzzphony'] ?? null;
        self::assertIsArray($fuzzphony);
        $indexes = $fuzzphony['indexes'] ?? null;
        self::assertIsArray($indexes);
        $products = $indexes['products'] ?? null;
        self::assertIsArray($products);

        /** @var array<string, mixed> $products */
        return $products;
    }

    /** @return array<int, string> id => name */
    private function names(): array
    {
        $names = [];
        foreach ($this->connection->fetchAll('SELECT id, name FROM product') as $row) {
            self::assertIsInt($row['id']);
            self::assertIsString($row['name']);
            $names[$row['id']] = $row['name'];
        }

        return $names;
    }

    /** @return list<string> */
    private function hitNames(SearchResult $result): array
    {
        $names = $this->names();

        return array_map(static fn(int|string $id): string => $names[(int) $id], $result->ids());
    }

    /** @return list<string> */
    private function likeNames(string $predicate): array
    {
        return array_map(static fn(array $row): string => is_string($row['name']) ? $row['name'] : '', $this->connection->fetchAll('SELECT name FROM product WHERE ' . $predicate . ' ORDER BY id'));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    public function testGuideShowsTheSnippetsThisTestRuns(): void
    {
        GuideSnippets::assertGuideShows(self::GUIDE, self::snippets());
    }

    public function testEveryCommandOfTheGuideExists(): void
    {
        $guide = (string) file_get_contents(self::GUIDE);
        $sources = [];
        foreach ((array) glob(__DIR__ . '/../../../src/Bundle/Command/*Command.php') as $file) {
            $source = (string) file_get_contents((string) $file);
            if (preg_match("/AsCommand\\(name: '([a-z:-]+)'/", $source, $m) === 1) {
                $sources[$m[1]] = $source;
            }
        }

        $seen = 0;
        foreach (explode("\n", $guide) as $line) {
            if (preg_match('/bin\/console (fuzzphony:[a-z:-]+)((?: [^#`]*)?)/', $line, $m) !== 1) {
                continue;
            }
            ++$seen;
            self::assertArrayHasKey($m[1], $sources, 'No such command: ' . $m[1]);
            preg_match_all('/--([a-z-]+)/', $m[2], $options);
            foreach ($options[1] as $option) {
                self::assertStringContainsString("'" . $option . "'", $sources[$m[1]], sprintf('%s has no option --%s', $m[1], $option));
            }
        }
        self::assertGreaterThanOrEqual(6, $seen);
    }

    public function testLikeCannotUseABtreeIndexAndNeedsTrigramsForSpeedOnly(): void
    {
        $this->boot();
        $this->connection->execute('CREATE INDEX product_name_btree ON product (name)');
        $this->connection->execute('ANALYZE product');
        $plan = fn(): string => implode("\n", array_map(static fn(array $row): string => is_string($line = reset($row)) ? $line : '', $this->connection->fetchAll("EXPLAIN SELECT id FROM product WHERE name ILIKE '%mouse%'")));

        $this->connection->execute('SET enable_seqscan = off');
        try {
            // even when sequential scans are made as unattractive as possible, a leading wildcard cannot use the B-tree
            self::assertStringContainsString('Seq Scan on product', $plan());
            self::assertStringNotContainsString('product_name_btree', $plan());

            // a trigram index makes the same predicate fast: it fixes the speed, none of the rest
            $this->connection->execute('CREATE INDEX product_name_trgm ON product USING gin (name gin_trgm_ops)');
            self::assertStringContainsString('product_name_trgm', $plan());
        } finally {
            $this->connection->execute('RESET enable_seqscan');
        }
    }

    public function testWhatIlikeMissesOnTheSameData(): void
    {
        $this->boot();
        $search = fn(string $text): array => $this->hitNames($this->fuzzphony->in('products')->query($text)->get());

        self::assertSame([], $this->likeNames("name ILIKE '%creme%'"));
        self::assertSame(['Crème brûlée torch'], $search('creme'), 'accents');
        self::assertSame([], $this->likeNames("name ILIKE '%drills%'"));
        self::assertSame(['Cordless drill', 'Drill bit set'], self::sorted($search('drills')), 'word forms');
        self::assertSame([], $this->likeNames("name ILIKE '%hedphones%'"));
        self::assertSame(['Wired headphones', 'Wireless noise cancelling headphones'], self::sorted(array_slice($search('hedphones'), 0, 2)), 'a typo');
    }

    public function testEveryRowOfTheMappingTable(): void
    {
        $this->boot();
        $guide = GuideSnippets::read((string) file_get_contents(self::GUIDE));
        $rows = $guide['tables']['mapping'] ?? [];
        self::assertNotSame([], $rows, 'the guide has no tested-table "mapping"');
        $header = array_shift($rows);
        self::assertIsArray($header);
        self::assertCount(3, $header);

        $mapping = self::mapping();
        $seen = [];
        foreach ($rows as $row) {
            self::assertCount(3, $row, implode(' | ', $row));
            if (preg_match('/^`(.+)`$/', $row[0], $like) !== 1 || preg_match('/^`(.+)`$/', $row[1], $text) !== 1) {
                continue; // a row about something that has no query ("not supported", "not needed")
            }
            $expected = $mapping[$text[1]] ?? null;
            self::assertNotNull($expected, 'The test does not know the query ' . $text[1]);
            $seen[] = $text[1];

            self::assertSame($expected['like'], $like[1], 'the LIKE column of ' . $text[1]);
            self::assertSame(self::sorted($expected['likeNames']), self::sorted($this->likeNames($like[1])), 'ILIKE: ' . $text[1]);

            $builder = $this->fuzzphony->in('products')->query($text[1]);
            $result = isset($expected['never']) ? $builder->thresholds(['fuzzy_mode' => 'never'])->get() : $builder->get();
            $found = $this->hitNames($result);
            if (isset($expected['names'])) {
                self::assertSame(self::sorted($expected['names']), self::sorted($found), 'Fuzzphony: ' . $text[1]);
            }
            if (isset($expected['first'])) {
                self::assertSame(self::sorted($expected['first']), self::sorted(array_slice($found, 0, count($expected['first']))), 'first hits of ' . $text[1]);
            }
            if ($text[1] === 'headphones -wireless') {
                self::assertNotContains('Wireless noise cancelling headphones', $found, 'the exclusion also holds for the look-alikes');
            }
        }
        self::assertEqualsCanonicalizing(array_keys($mapping), $seen, 'The guide and the test describe different rows');
    }

    public function testASuffixPatternIsNotASearchAndAPercentSignIsNotAWildcard(): void
    {
        $this->boot();
        $never = ['fuzzy_mode' => 'never'];
        // the leading * is dropped: `*phones` is the word "phones" (and its stem), not "anything ending in phones"
        self::assertSame('phones', $this->fuzzphony->in('products')->query('*phones')->get()->interpretedAs);
        self::assertSame(['Phone case'], $this->hitNames($this->fuzzphony->in('products')->query('*phones')->thresholds($never)->get()));
        // %, _ are just punctuation to the query
        self::assertSame([], $this->hitNames($this->fuzzphony->in('products')->query('wire%mouse')->thresholds($never)->get()));
        self::assertSame([], $this->hitNames($this->fuzzphony->in('products')->query('mou_e')->thresholds($never)->get()));
        // with typo tolerance on, the look-alike words make the suffix search look as if it worked: not a promise
        $tolerant = $this->hitNames($this->fuzzphony->in('products')->query('phones')->get());
        self::assertSame('Phone case', $tolerant[0]);
        self::assertContains('Wired headphones', $tolerant);
    }

    public function testAnExactTitleIsRankedFirstButEqualityStaysSql(): void
    {
        $this->boot();
        $out = self::runSnippet('exact', ['fuzzphony' => $this->fuzzphony, 'text' => '"wireless mouse"']);
        self::assertInstanceOf(SearchResult::class, $out['result']);
        self::assertFalse($out['result']->usedFuzzy);
        self::assertSame('Wireless mouse', $this->hitNames($out['result'])[0]);
        self::assertContains('Wireless mouse pad', $this->hitNames($out['result']), 'a phrase is contained, not equal');

        // never: a typo finds nothing, and so does a word that is not there; stemming and accents still apply
        $typo = self::runSnippet('exact', ['fuzzphony' => $this->fuzzphony, 'text' => 'hedphones']);
        self::assertInstanceOf(SearchResult::class, $typo['result']);
        self::assertSame([], $typo['result']->ids());
        $accent = self::runSnippet('exact', ['fuzzphony' => $this->fuzzphony, 'text' => 'creme']);
        self::assertInstanceOf(SearchResult::class, $accent['result']);
        self::assertSame(['Crème brûlée torch'], $this->hitNames($accent['result']));
    }

    public function testEntitiesComeBackInRankOrderAndTheDatabaseOrderIsNotTheRankOrder(): void
    {
        $this->boot();
        $result = $this->fuzzphony->in('products')->query('mouse')->get();
        $ids = $result->ids();
        self::assertCount(5, $ids);
        $sorted = $ids;
        sort($sorted);
        self::assertNotSame($sorted, $ids, 'otherwise the test could not tell rank order from id order');

        // what the guide shows when you cannot use EntityLoader
        $out = self::runSnippet('by-ids', ['em' => $this->em, 'result' => $result]);
        self::assertSame($ids, array_map(self::idOf(...), self::entities($out['products'])));

        // and EntityLoader does the same
        $loaded = (new EntityLoader($this->em))->load($result, self::productClass());
        self::assertSame($ids, array_map(static fn(array $row): int|string => self::idOf($row['entity']), $loaded));

        // a row deleted since the last sync (queue mode) is skipped by both
        $this->em->clear();
        $this->connection->execute('ALTER TABLE product DISABLE TRIGGER USER');
        $this->connection->execute('DELETE FROM product WHERE id = :id', ['id' => $ids[1]]);
        $out = self::runSnippet('by-ids', ['em' => $this->em, 'result' => $result]);
        self::assertCount(4, self::entities($out['products']));
        self::assertCount(4, (new EntityLoader($this->em))->load($result, self::productClass()));
    }

    /** @return list<object> */
    private static function entities(mixed $products): array
    {
        self::assertIsArray($products);
        $entities = [];
        foreach ($products as $product) {
            self::assertIsObject($product);
            $entities[] = $product;
        }

        return $entities;
    }

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function idOf(object $entity): int|string
    {
        $id = (new \ReflectionProperty($entity, 'id'))->getValue($entity);
        self::assertTrue(is_int($id) || is_string($id));

        return $id;
    }

    public function testTheServiceKeepsYourFiltersAndRanksByPopularityOnRequest(): void
    {
        $this->boot();
        self::declare('search');
        $service = new (self::loadedClass('App\Search\ProductSearch'))($this->fuzzphony, new EntityLoader($this->em));
        self::assertIsCallable($service);
        /** @param list<string> $categories */
        $search = static function (string $text, ?int $maxPrice = null, array $categories = []) use ($service): array {
            $found = $service($text, $maxPrice, $categories);
            self::assertIsArray($found);

            return $found;
        };
        $names = static fn(array $found): array => array_values(array_map(static fn(object $p): string => self::stringProperty($p, 'name'), self::entities($found['products'] ?? null)));

        $found = $search('mouse');
        self::assertSame(5, $found['total']);
        self::assertFalse($found['more']);
        self::assertSame('Wireless mouse', $names($found)[0], 'the popular profile puts the best seller first');
        $default = $this->hitNames($this->fuzzphony->in('products')->query('mouse')->get());
        self::assertSame('Mouse trap', $default[0], 'without it the name that starts with the word wins');

        self::assertSame(['Ergonomic mouse pad', 'Mouse trap', 'USB receiver', 'Wireless mouse'], self::sorted($names($search('mouse', maxPrice: 2_500))), 'price <= 2500 leaves out the 3500 pad');
        self::assertSame(['Ergonomic mouse pad', 'Mouse trap', 'USB receiver'], self::sorted($names($search('mouse', maxPrice: 1_500))));
        self::assertSame(['Cordless drill'], $names($search('drill', categories: ['tools'])), 'the out-of-stock drill bit set is filtered like it was in your WHERE');
        self::assertSame([], $names($search('drill', categories: ['audio'])));
    }

    /** @return class-string */
    private static function productClass(): string
    {
        return self::loadedClass(self::PRODUCT);
    }

    /** @return class-string */
    private static function loadedClass(string $class): string
    {
        self::assertTrue(class_exists($class), $class);

        return $class;
    }

    private static function stringProperty(object $object, string $property): string
    {
        $value = (new \ReflectionProperty($object, $property))->getValue($object);
        self::assertIsString($value);

        return $value;
    }

    public function testPaginationAndTheLowerBound(): void
    {
        $this->boot(thresholds: new Thresholds(candidateLimit: 10), cables: 30);

        $out = self::runSnippet('pagination', ['fuzzphony' => $this->fuzzphony, 'text' => 'cable', 'page' => 1]);
        self::assertSame('1-10 of 10+', $out['label'], 'candidate_limit reached: the total is a lower bound, and only the candidates can be paged');
        self::assertTrue($out['hasNextPage']);
        $beyond = $this->fuzzphony->in('products')->query('cable')->page(3, perPage: 5)->get();
        self::assertSame([], $beyond->ids(), 'a page past the candidates is empty, although the total says "more"');

        $out = self::runSnippet('pagination', ['fuzzphony' => $this->fuzzphony, 'text' => 'mouse', 'page' => 1]);
        self::assertSame('1-5 of 5', $out['label']);
        self::assertFalse($out['hasNextPage']);
    }

    public function testAnEmptyTextIsABrowseOrderedByTheProfileNotByAColumn(): void
    {
        $this->boot();
        $browse = $this->fuzzphony->in('products')->query('')->where('in_stock', true)->get();
        self::assertSame(15, $browse->total, 'the filters still apply');
        self::assertSame(range(1, 6), array_slice($browse->ids(), 0, 6), 'no profile: every match scores 0, in id order');
        self::assertSame(0.0, $browse->hits[0]->score);

        // ORDER BY popularity DESC: the boost column, weighted by a profile
        $popular = $this->fuzzphony->in('products')->query('')->profile('popular')->limit(4)->get();
        self::assertSame(['Wireless noise cancelling headphones', 'Cordless drill', 'Mechanical keyboard', 'Wireless mouse'], $this->hitNames($popular));

        // ORDER BY published_at DESC: the recency column; a decaying bonus, so newest first when nothing else counts
        $this->connection->execute("UPDATE product SET published_at = '2026-01-01'::timestamptz + id * interval '1 day'");
        $newest = $this->fuzzphony->in('products')->query('')->ranking(['boost' => 0.0, 'recency' => 1.0])->limit(3)->get();
        self::assertSame([16, 15, 14], $newest->ids());
    }

    public function testWhatChangesComparedWithSubstringMatching(): void
    {
        $this->boot();
        $search = fn(string $text, bool $exact = false): SearchResult => $exact
            ? $this->fuzzphony->in('products')->query($text)->thresholds(['fuzzy_mode' => 'never'])->get()
            : $this->fuzzphony->in('products')->query($text)->get();

        // a fragment inside a word: ILIKE finds it, an exact search does not, typo tolerance only by similarity
        self::assertCount(3, $this->likeNames("name ILIKE '%eadphon%'"));
        self::assertSame([], $search('eadphon', exact: true)->ids());
        self::assertTrue($search('eadphon')->usedFuzzy);
        self::assertContains('Wired headphones', $this->hitNames($search('eadphon')));

        // plenty of exact hits: the typo-tolerant search does not run
        self::assertFalse($search('mouse')->usedFuzzy);
        self::assertTrue($search('creme')->usedFuzzy);

        // stop words are ignored
        self::assertSame($search('mouse')->ids(), $search('the mouse')->ids());

        // 16 terms and 256 characters
        $long = $search(implode(' ', array_fill(0, 17, 'mouse')));
        self::assertContains('Only the first 16 terms were used.', $long->warnings);

        // conditions are ANDed, and only declared filters exist
        $found = $this->fuzzphony->in('products')->query('')->where('category', 'audio')->where('price', '<', 5_000)->get();
        self::assertSame(['Headphone stand', 'Earphones'], $this->hitNames($found));
        try {
            $this->fuzzphony->in('products')->query('mouse')->where('prise', '<', 1)->get();
            self::fail('an unknown filter must throw');
        } catch (\Fuzzphony\Core\Exception\FuzzphonyException $e) {
            self::assertStringContainsString('Did you mean "price"?', $e->getMessage());
        }
    }

    public function testASimpleLanguageWithoutAccentFoldingMatchesLetterForLetter(): void
    {
        $this->boot();
        $definition = $this->definition->withText(new \Fuzzphony\Core\Definition\TextConfig('simple', false));
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');
        $exact = static fn(string $text): array => $fuzzphony->in('products')->query($text)->thresholds(['fuzzy_mode' => 'never'])->get()->ids();

        $drill = $exact('drill');
        sort($drill);
        self::assertSame([6, 7], $drill);
        self::assertSame([], $exact('drills'), 'no stemming');
        self::assertSame([], $exact('creme'), 'no accent folding');
        self::assertSame([5], $exact('crème'));
    }

    public function testTheIndexFollowsYourWritesAsTheSyncModeSays(): void
    {
        $this->boot(SyncMode::Trigger);
        $count = static fn(Fuzzphony $f): int => $f->in('products')->query('gadget')->get()->total;
        $this->em->persist(self::make(self::productClass(), ['name' => 'Gadget', 'category' => 'office', 'price' => 1, 'publishedAt' => new \DateTimeImmutable()]));
        $this->em->flush();
        self::assertSame(1, $count($this->fuzzphony), 'trigger: visible in the same transaction, like LIKE');

        $this->connection->execute("UPDATE product SET name = 'Widget' WHERE name = 'Gadget'");
        self::assertSame(0, $count($this->fuzzphony), 'an update re-indexes the row');

        $this->boot(SyncMode::Queue);
        $this->connection->execute("INSERT INTO product (name, description, category, price, in_stock, popularity, published_at) VALUES ('Gadget', '', 'office', 1, true, 0, now())");
        self::assertSame(0, $count($this->fuzzphony), 'queue: not searchable until the worker has run');
        self::assertSame(1, $this->fuzzphony->engine()->queueSize($this->definition));
        (new Worker($this->fuzzphony->engine()))->runOnce([$this->definition]);
        self::assertSame(1, $count($this->fuzzphony), 'queue: searchable after the worker (fuzzphony:worker)');

        $this->connection->execute("DELETE FROM product WHERE name = 'Gadget'");
        (new Worker($this->fuzzphony->engine()))->runOnce([$this->definition]);
        self::assertSame(0, $count($this->fuzzphony), 'a delete removes the document');
    }

    public function testAJoinedLabelIsSearchableAndFollowsTheJoinedTable(): void
    {
        $this->boot();
        $this->connection->execute('CREATE TABLE supplier (id int PRIMARY KEY, name text NOT NULL)');
        $this->connection->execute('ALTER TABLE product ADD COLUMN supplier_id int REFERENCES supplier');
        $this->connection->execute("INSERT INTO supplier VALUES (1, 'Acme Peripherals'), (2, 'Globex')");
        $this->connection->execute('UPDATE product SET supplier_id = 1 WHERE id IN (1, 10)');

        // the YAML the guide shows, loaded as the bundle loads it
        $definition = (new ArrayDefinitionLoader())->load('products', self::indexConfig(Yaml::parse(self::snippet('joined-yaml'))));
        $fuzzphony = new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
        $this->connection->execute('DROP TABLE fuzzphony_products, fuzzphony_products__vocab CASCADE');
        $fuzzphony->schema()->apply($this->connection);
        $fuzzphony->reindex('products');

        self::assertSame(2, $fuzzphony->in('products')->query('acme')->get()->total, 'the supplier name is a field of the product document');
        self::assertSame(2, $fuzzphony->in('products')->query('acme mouse')->get()->total);

        $this->connection->execute("UPDATE supplier SET name = 'Initech' WHERE id = 1");
        self::assertSame(0, $fuzzphony->in('products')->query('acme')->get()->total, 'a renamed supplier re-indexes its products (watch)');
        self::assertSame(2, $fuzzphony->in('products')->query('initech')->get()->total);

        $ids = $fuzzphony->in('products')->query('initech')->get()->ids();
        sort($ids);
        self::assertSame([1, 10], $ids, 'a hit is a product (its id), found through the supplier');
    }

    public function testShadowSearchServesOneAndLogsTheDifferenceToTheOther(): void
    {
        $this->boot();
        self::declare('shadow');
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $class = self::loadedClass('App\Search\SearchRollout');

        // serving LIKE, shadowing Fuzzphony: what the new search would have found extra, and what it lacks
        $rollout = new $class($this->fuzzphony, $this->em, $logger, false);
        self::assertSame([], self::rolloutIds($rollout, 'creme'));
        self::assertSame('search shadow', $logger->records[0][0]);
        self::assertSame(['q' => 'creme', 'only_served' => [], 'only_other' => [5]], $logger->records[0][1]);

        // serving Fuzzphony: the same difference seen from the other side
        $rollout = new $class($this->fuzzphony, $this->em, $logger, true);
        self::assertSame([5], self::rolloutIds($rollout, 'creme'));
        self::assertSame(['q' => 'creme', 'only_served' => [5], 'only_other' => []], $logger->records[1][1]);

        // when the shadow search fails the user still gets the served result, and the failure is logged
        $this->connection->execute('DROP TABLE fuzzphony_products CASCADE');
        $rollout = new $class($this->fuzzphony, $this->em, $logger, false);
        self::assertCount(4, self::rolloutIds($rollout, 'mouse'));
        self::assertSame('search shadow failed', $logger->records[2][0]);
    }

    /** @return list<mixed> */
    private static function rolloutIds(object $rollout, string $text): array
    {
        $ids = [$rollout, 'ids'];
        self::assertIsCallable($ids);
        $found = $ids($text);
        self::assertIsArray($found);

        return array_values($found);
    }

    public function testExplainShowsTheSqlAndThePlan(): void
    {
        $this->boot();
        ob_start();
        self::runSnippet('explain', ['fuzzphony' => $this->fuzzphony, 'text' => 'mouse']);
        $output = (string) ob_get_clean();

        self::assertStringContainsString("mouse\nfull-text\n", $output, 'interpretedAs, then the first statement');
        self::assertStringContainsString('fuzzphony_products', $output);
        self::assertMatchesRegularExpression('/^\s*Sort\s/m', $output, 'the plan');
        self::assertMatchesRegularExpression('/\d+(\.\d+)? ms$/', trim($output));
    }

    public function testDroppingTheIndexLeavesYourTableAndYourLikeQueryAlone(): void
    {
        $this->boot();
        $before = $this->likeNames("name ILIKE '%mouse%'");
        self::assertNotSame([], $before);

        // bin/console fuzzphony:schema --drop --apply
        $this->fuzzphony->engine()->dropSchema($this->definition)->apply($this->connection);

        self::assertSame(0, self::asInt($this->connection->fetchValue("SELECT count(*) FROM pg_tables WHERE tablename IN ('fuzzphony_products', 'fuzzphony_products__vocab')")));
        self::assertSame(0, self::asInt($this->connection->fetchValue("SELECT count(*) FROM pg_trigger WHERE tgrelid = 'product'::regclass AND NOT tgisinternal")), 'its triggers are gone from your table');
        self::assertSame($before, $this->likeNames("name ILIKE '%mouse%'"));
        $this->connection->execute("INSERT INTO product (name, description, category, price, in_stock, popularity, published_at) VALUES ('Mouse', '', 'office', 1, true, 0, now())");
        self::assertCount(count($before) + 1, $this->likeNames("name ILIKE '%mouse%'"), 'writes work as before');
    }

    public function testTheWizardProposesADefinitionForTheTable(): void
    {
        $this->boot();
        $this->connection->execute('ANALYZE product');
        $suggestion = (new DefinitionSuggester())->suggest((new PostgresIntrospector($this->connection))->describe('product'), null, 'english');

        $roles = [];
        foreach ($suggestion->decisions as $decision) {
            $roles[$decision->column][] = $decision->role;
        }
        self::assertSame(['field A, fuzzy'], $roles['name']);
        self::assertSame(['boost'], $roles['popularity']);
        self::assertContains('recency', $roles['published_at']);
        self::assertContains('filter', $roles['price']);
        self::assertContains('filter', $roles['in_stock']);
        self::assertNotNull($suggestion->definition);
    }
}
