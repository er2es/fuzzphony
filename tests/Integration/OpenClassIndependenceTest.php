<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * An index with granted fields (brand, description) has two more indexes over its open fields (name, category): a viewer who holds
 * none of the granted fields looks its words up in them (OpenClass). Whatever the granted fields hold, and however much, changes nothing
 * that viewer finds (hits, scores, totals, facets, did-you-mean), the viewers who hold a granted field and the unrestricted one keep the
 * statements they always had, and every outcome is the one the index without the open-class indexes gives.
 */
#[Group('slow')]
final class OpenClassIndependenceTest extends TestCase
{
    private const int ROWS = 3000;
    private const array QUERIES = [
        'ideal', 'ideal battery', 'ideal battery wireless gaming office', '"ideal battery"', '"alpha beta"', 'alpha beta', 'kettle makita', 'makita',
        'zorbaxx', 'zzqq', 'kitchen zorbaxx', 'mouse -pro', 'name:mouse', 'brand:makita', 'batery', 'wireles mouse', 'keybord', 'mouse*', 'key*',
        'ideal -wireless', 'alpha | zorbaxx', 'proffesional', 'description:zzqq', '"kettle kitchen"',
    ];

    private Connection $connection;
    /** @var list<array{int, string, string, string, string, string}> */
    private array $base = [];

    protected function setUp(): void
    {
        $this->connection = PostgresTestCase::connect();
        // a sequential scan that starts mid-table (synchronized scans) picks other candidates when there are more than the limit: it
        // would make one run differ from the next on identical data, today's statements included
        $this->connection->execute('SET synchronize_seqscans = off');
        $this->connection->execute('DROP TABLE IF EXISTS fz_oc_item, fuzzphony_oc_items, fuzzphony_oc_items__vocab, fuzzphony_oc_items__next, fuzzphony_oc_items__changes CASCADE');
        $this->connection->execute('CREATE TABLE fz_oc_item (id bigint PRIMARY KEY, name text NOT NULL, category text NOT NULL, brand text NOT NULL, description text NOT NULL, cat text NOT NULL)');
        mt_srand(20261028);
        $things = ['kettle', 'mouse', 'keyboard', 'headphones', 'monitor', 'charger', 'speaker', 'lamp'];
        $kinds = ['pro', 'mini', 'ultra', 'smart', 'classic'];
        $categories = ['audio', 'office', 'kitchen', 'gaming', 'mobile', 'home office'];
        $brands = ['makita', 'bosch', 'sony', 'logitech', 'philips', 'dewalt'];
        $words = ['ideal', 'battery', 'wireless', 'gaming', 'keyboard', 'mouse', 'office', 'compact', 'silent', 'ergonomic', 'professional', 'lightweight', 'durable', 'portable'];
        for ($id = 5; $id <= self::ROWS; ++$id) {
            $description = implode(' ', array_map(static fn(): string => $words[mt_rand(0, count($words) - 1)], range(1, 14)));
            $this->base[] = [$id, $things[mt_rand(0, 7)] . ' ' . $kinds[mt_rand(0, 4)] . ' ' . mt_rand(100, 999), $categories[mt_rand(0, 5)], $brands[mt_rand(0, 5)], $description, $id % 2 === 0 ? 'a' : 'b'];
        }
        // a phrase across two open fields; a word of a granted field next to a word of an open one; a word only in one granted field
        array_push(
            $this->base,
            [1, 'alpha', 'beta', 'gamma', 'delta', 'a'],
            [2, 'kettle', 'kitchen', 'makita', 'plain', 'b'],
            [3, 'plain', 'office', 'bosch', 'uniqueword zzqq', 'a'],
            [4, 'lamp', 'home', 'zorbaxx', 'ideal battery', 'b'],
        );
        usort($this->base, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    }

    private function fuzzphony(): Fuzzphony
    {
        $definition = IndexDefinition::builder('oc_items')->fromQuery('SELECT id, name, category, brand, description, cat FROM fz_oc_item')
            ->field('name', 'A', fuzzy: true)->field('category', 'B', fuzzy: true)
            ->field('brand', 'B', fuzzy: true, granted: 'ROLE_BRAND')->field('description', 'C', fuzzy: true, granted: 'ROLE_DESCRIPTION')
            ->filter('cat', 'string')->language('english')->sync('manual')->build();

        return new Fuzzphony(new PostgresEngine($this->connection), new IndexRegistry([$definition]));
    }

    /**
     * @param list<string> $hidden the fields to change: 'empty', 'long' (17 000 words in the first rows) or 'words' (the query words) replace their text
     */
    private function load(Fuzzphony $fuzzphony, string $variant, array $hidden): void
    {
        $this->connection->execute('DELETE FROM fz_oc_item');
        $long = implode(' ', array_map(static fn(int $i): string => 'h' . $i, range(1, 17_000)));
        $columns = ['name' => 1, 'category' => 2, 'brand' => 3, 'description' => 4];
        foreach (array_chunk($this->base, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $n => $row) {
                foreach ($hidden as $field) {
                    $row[$columns[$field]] = match ($variant) {
                        'empty' => '',
                        'long' => $row[0] <= 3 ? $long : $row[$columns[$field]],
                        'words' => $row[$columns[$field]] . ' ideal battery wireless gaming office alpha beta kettle makita mouse keyboard',
                        default => $row[$columns[$field]],
                    };
                }
                $values[] = sprintf('(:a%1$d, :b%1$d, :c%1$d, :d%1$d, :e%1$d, :f%1$d)', $n);
                foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $i => $key) {
                    $params[$key . $n] = $row[$i];
                }
            }
            $this->connection->execute('INSERT INTO fz_oc_item VALUES ' . implode(', ', $values), $params);
        }
        $fuzzphony->reindex('oc_items');
    }

    /**
     * @param list<string>|null $allowed
     *
     * @return array{outcomes: array<string, mixed>, open: bool} what each query finds, and whether any statement went through the open-class indexes
     */
    private function outcomes(Fuzzphony $fuzzphony, ?array $allowed): array
    {
        $outcomes = [];
        $open = false;
        foreach (self::QUERIES as $text) {
            foreach (['fallback', 'always', 'never'] as $mode) {
                $builder = $fuzzphony->in('oc_items')->query($text)->thresholds(['fuzzy_mode' => $mode])->facets('cat')->limit(5);
                $explained = ($allowed === null ? $builder : $builder->restrictedToFields(...$allowed))->explain();
                $outcomes[$text . ' | ' . $mode] = ResultSnapshot::of($explained->result);
                foreach ($explained->statements as $statement) {
                    $open = $open || preg_match('/WHERE \(s\."t_name" \|\| s\."t_category"\) @@ q\.ts[qp]|<%\) \(s\."z_name" \|\| \' \' \|\| s\."z_category"\)/', $statement['sql']) === 1;
                }
            }
        }

        return ['outcomes' => $outcomes, 'open' => $open];
    }

    /** @return iterable<string, array{list<string>}> the fields of the open class only: the viewers whose lookups go through the open-class indexes */
    public static function openViewers(): iterable
    {
        yield 'name and category' => [['name', 'category']];
        yield 'name only' => [['name']];
        yield 'category only' => [['category']];
    }

    /** @param list<string> $allowed */
    #[DataProvider('openViewers')]
    public function testWhatTheGrantedFieldsHoldChangesNothingForAViewerWhoHoldsNone(array $allowed): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->schema()->apply($this->connection);
        $hidden = ['brand', 'description', ...array_diff(['name', 'category'], $allowed)];
        $reference = null;
        foreach (['base', 'empty', 'long', 'words'] as $variant) {
            $this->load($fuzzphony, $variant, $hidden);
            $fresh = $this->fuzzphony(); // a fresh engine: the gate is asked again for this size of the indexes
            $run = $this->outcomes($fresh, $allowed);
            // (the long text of the open fields makes the open-class indexes the larger ones: the size rule then keeps today's statements)
            if ($variant === 'base' || $variant === 'words') {
                self::assertTrue($run['open'], $variant . ': the statements use the open-class indexes');
            }
            if ($variant === 'base') {
                $reference = $run['outcomes'];
                continue;
            }
            // the viewer sees the same fields in every variant: only what it may not see changed
            self::assertSame($reference, $run['outcomes'], $variant . ': a hidden field changed what the viewer finds');
        }
    }

    public function testTheOutcomeIsTheOneTheIndexWithoutTheOpenClassIndexesGives(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->schema()->apply($this->connection);
        $this->load($fuzzphony, 'base', []);
        $viewers = [['name', 'category'], ['name'], ['category'], ['name', 'brand'], ['name', 'category', 'brand', 'description'], ['brand'], ['description', 'category'], null];
        $with = [];
        foreach ($viewers as $i => $allowed) {
            $with[$i] = $this->outcomes($this->fuzzphony(), $allowed);
        }
        $this->connection->execute('DROP INDEX fuzzphony_oc_items_open_fz');
        $this->connection->execute('DROP INDEX fuzzphony_oc_items_open_tsv');
        foreach ($viewers as $i => $allowed) {
            $without = $this->outcomes($this->fuzzphony(), $allowed);
            self::assertFalse($without['open'], 'no open-class index: today\'s statements');
            self::assertSame($without['outcomes'], $with[$i]['outcomes'], 'viewer ' . json_encode($allowed) . ': the open-class indexes changed an outcome');
            // only a viewer of the open class used them; everyone else (one granted field, all, none restricted) kept today's statements
            self::assertSame(in_array($allowed, [['name', 'category'], ['name'], ['category']], true), $with[$i]['open'], 'viewer ' . json_encode($allowed));
        }
    }

    /** @return list<int|string> */
    private function ids(Fuzzphony $fuzzphony, string $text, string ...$allowed): array
    {
        return array_map(static fn($hit): int|string => $hit->id, $fuzzphony->in('oc_items')->query($text)->thresholds(['fuzzy_mode' => 'never'])->restrictedToFields(...$allowed)->get()->hits);
    }

    public function testAWordOnlyAGrantedFieldHoldsIsNotFoundByAViewerWhoHoldsNoneOfThem(): void
    {
        $fuzzphony = $this->fuzzphony();
        $fuzzphony->schema()->apply($this->connection);
        $this->load($fuzzphony, 'base', []);
        $total = static fn(string $text, string ...$allowed): int => $fuzzphony->in('oc_items')->query($text)->thresholds(['fuzzy_mode' => 'never'])->restrictedToFields(...$allowed)->get()->total;

        self::assertSame([], $this->ids($fuzzphony, 'zorbaxx', 'name', 'category'), 'a brand');
        // 'makita' is a brand: the AND of an open and a granted word finds nothing for the viewer who holds no brand, and the search then
        // relaxes to the word it can find ('kettle'); with the brand the AND holds
        self::assertSame($this->ids($fuzzphony, 'kettle', 'name', 'category'), $this->ids($fuzzphony, 'kettle makita', 'name', 'category'));
        self::assertContains(2, $this->ids($fuzzphony, 'kettle makita', 'name', 'category', 'brand'), 'with the brand the document is found');
        self::assertLessThan($total('kettle', 'name', 'category'), $total('kettle makita', 'name', 'category', 'brand'));
        self::assertSame([4], $this->ids($fuzzphony, 'zorbaxx', 'name', 'brand'));
        self::assertSame([3], $this->ids($fuzzphony, 'zzqq', 'description', 'name'));
        self::assertSame([1], $this->ids($fuzzphony, '"alpha beta"', 'name', 'category'), 'a phrase across two open fields');
    }
}
