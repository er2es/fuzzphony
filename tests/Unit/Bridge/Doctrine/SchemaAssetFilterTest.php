<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Fuzzphony\Bridge\Doctrine\SchemaAssetFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaAssetFilterTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> schema, asset name as DBAL passes it, kept */
    public static function names(): iterable
    {
        yield 'public: application table' => ['public', 'product', true];
        yield 'public: qualified application table' => ['public', 'public.product', true];
        yield 'public: sidecar' => ['public', 'fuzzphony_products', false];
        yield 'public: qualified queue' => ['public', 'public.fuzzphony_queue', false];
        yield 'public: similar prefix' => ['public', 'fuzzphonyx', true];
        yield 'public: fuzzphony_ table in another schema' => ['public', 'shop.fuzzphony_x', true];
        yield 'dedicated: its tables' => ['fuzzphony', 'fuzzphony.fuzzphony_products', false];
        yield 'dedicated: anything in it' => ['fuzzphony', 'fuzzphony.other', false];
        yield 'dedicated: public table' => ['fuzzphony', 'product', true];
        yield 'dedicated: schema with the same prefix' => ['fuzzphony', 'fuzzphony2.product', true];
    }

    #[DataProvider('names')]
    public function testTheFilterHidesOnlyFuzzphonysObjects(string $schema, string $asset, bool $kept): void
    {
        self::assertSame($kept, preg_match(SchemaAssetFilter::regex($schema), $asset) === 1);
    }

    public function testTheRegexes(): void
    {
        self::assertSame('~^(?!(public\.)?fuzzphony_)~', SchemaAssetFilter::regex('public'));
        self::assertSame('~^(?!fuzzphony\.)~', SchemaAssetFilter::regex('fuzzphony'));
    }
}
