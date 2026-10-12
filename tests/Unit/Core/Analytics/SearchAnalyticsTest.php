<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Analytics;

use Fuzzphony\Core\Analytics\QueryStat;
use Fuzzphony\Core\Analytics\SearchAnalytics;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\SearchLogStore;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchAnalyticsTest extends TestCase
{
    private function analytics(bool $tenant, FakeSearchLogStore $store): SearchAnalytics
    {
        return new SearchAnalytics($store, new IndexRegistry([Indexes::products(tenant: $tenant)]));
    }

    public function testATenantScopedIndexNeedsATenantOrAllTenants(): void
    {
        $analytics = $this->analytics(true, new FakeSearchLogStore());

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('allTenants');
        $analytics->topQueries('products', new \DateTimeImmutable('-7 days'));
    }

    public function testATenantIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $result = $this->analytics(true, $store)->topQueries('products', $from, null, 5, 3);

        self::assertSame($store->stats, $result);
        self::assertSame([['top', 'products', $from, null, 5, 3, false]], $store->calls);
    }

    public function testAllTenantsIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');
        $to = new \DateTimeImmutable('-1 day');

        $this->analytics(true, $store)->zeroResultQueries('products', $from, $to, allTenants: true);

        self::assertSame([['zero', 'products', $from, $to, 20, null, true]], $store->calls);
    }

    public function testAStringTenantIsPassedToTheStore(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $this->analytics(true, $store)->zeroResultQueries('products', $from, tenant: 'acme');

        self::assertSame([['zero', 'products', $from, null, 20, 'acme', false]], $store->calls);
    }

    public function testAnIndexWithoutATenantIgnoresBoth(): void
    {
        $store = new FakeSearchLogStore();
        $from = new \DateTimeImmutable('-7 days');

        $analytics = $this->analytics(false, $store);
        $analytics->topQueries('products', $from, tenant: 3, allTenants: true);
        $analytics->zeroResultQueries('products', $from);

        self::assertSame([['top', 'products', $from, null, 20, null, false], ['zero', 'products', $from, null, 20, null, false]], $store->calls);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function limits(): iterable
    {
        yield '0' => [0, false];
        yield '1' => [1, true];
        yield '1000' => [1000, true];
        yield '1001' => [1001, false];
    }

    #[DataProvider('limits')]
    public function testTheLimitIsOneToAThousand(int $limit, bool $allowed): void
    {
        $store = new FakeSearchLogStore();
        $analytics = $this->analytics(false, $store);
        if (!$allowed) {
            $this->expectException(InvalidArgument::class);
            $this->expectExceptionMessage(sprintf('The limit must be between 1 and 1000, got %d.', $limit));
        }

        $analytics->topQueries('products', new \DateTimeImmutable(), limit: $limit);

        self::assertCount(1, $store->calls);
    }

    public function testThePurgeIsDelegated(): void
    {
        $store = new FakeSearchLogStore();
        $before = new \DateTimeImmutable('-90 days');

        self::assertSame(7, $this->analytics(false, $store)->purge($before));
        self::assertSame([['purge', $before]], $store->calls);
    }

    public function testFuzzphonyHandsOutAnalyticsWhenTheEngineKeepsALog(): void
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, SearchLogStore::class]);
        assert($engine instanceof Engine);

        self::assertInstanceOf(SearchAnalytics::class, (new Fuzzphony($engine, new IndexRegistry([Indexes::products()])))->analytics());
    }

    public function testFuzzphonyRefusesAnalyticsWhenTheEngineKeepsNoLog(): void
    {
        $engine = self::createStub(Engine::class);
        $engine->method('name')->willReturn('fake');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('The "fake" engine keeps no search log.');
        (new Fuzzphony($engine, new IndexRegistry([Indexes::products()])))->analytics();
    }
}

/** A store that records what it was asked and returns a fixed list. */
final class FakeSearchLogStore implements SearchLogStore
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];
    /** @var list<QueryStat> */
    public array $stats;

    public function __construct()
    {
        $this->stats = [new QueryStat('mouse', 4, 2.5, new \DateTimeImmutable('2026-10-01'))];
    }

    public function writeSearchLog(array $records): int
    {
        return count($records);
    }

    public function purgeSearchLog(\DateTimeInterface $before): int
    {
        $this->calls[] = ['purge', $before];

        return 7;
    }

    public function topQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $this->calls[] = ['top', $index->name, $from, $to, $limit, $tenant, $allTenants];

        return $this->stats;
    }

    public function zeroResultQueries(IndexDefinition $index, \DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, int|string|null $tenant, bool $allTenants): array
    {
        $this->calls[] = ['zero', $index->name, $from, $to, $limit, $tenant, $allTenants];

        return $this->stats;
    }
}
