<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Wizard;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Wizard\Export\ArrayExporter;
use PHPUnit\Framework\TestCase;

final class ArrayExporterMutationsTest extends TestCase
{
    private static function base(): \Fuzzphony\Core\Definition\IndexBuilder
    {
        return IndexDefinition::builder('products')->fromTable('product')->field('name', 'B');
    }

    public function testAnUnchangedDefaultProfileIsNotExported(): void
    {
        self::assertArrayNotHasKey('profiles', (new ArrayExporter())->export(self::base()->build()));
    }

    public function testAChangedDefaultProfileIsExportedAsItsDifference(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->profile('default', new RankingProfile(fuzzy: 0.25))->build());

        self::assertSame(['default' => ['fuzzy' => 0.25]], $exported['profiles'] ?? null);
    }

    public function testANamedProfileIsExportedEvenWhenItEqualsTheDefaults(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->profile('plain', new RankingProfile())->build());

        self::assertSame(['plain' => []], $exported['profiles'] ?? null);
    }

    public function testANamedProfileIsExportedAsItsDifference(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->profile('loud', new RankingProfile(exactBonus: 2.0))->build());

        self::assertSame(['loud' => ['exact_bonus' => 2.0]], $exported['profiles'] ?? null);
    }

    public function testAWatchOnTheIdKeyWithoutColumnsIsJustItsQuery(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->watch('brand', 'SELECT id FROM product WHERE brand_id = :id')->build());

        self::assertSame(['brand' => 'SELECT id FROM product WHERE brand_id = :id'], $exported['watch'] ?? null);
    }

    public function testAWatchWithAnotherKeyKeepsItAndLeavesOutWhatIsUnset(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->watch('brand', 'SELECT :id', 'pid')->build());

        self::assertSame(['brand' => ['ids' => 'SELECT :id', 'key' => 'pid']], $exported['watch'] ?? null);
    }

    public function testAWatchWithColumnsOnTheIdKeyLeavesOutTheKey(): void
    {
        $exported = (new ArrayExporter())->export(self::base()->watch('brand', 'SELECT :id', 'id', ['name'])->build());

        self::assertSame(['brand' => ['ids' => 'SELECT :id', 'columns' => ['name']]], $exported['watch'] ?? null);
    }
}
