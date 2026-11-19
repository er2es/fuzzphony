<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Sync\Worker;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * apply -> drop -> apply gives the catalog of the first apply; an apply over a changed definition followed by a reindex gives
 * the catalog of a fresh install of the new definition, a clean doctor and correct searches.
 */
#[Group('hardening')]
final class ApplyIdempotenceTest extends TestCase
{
    private Connection $c;

    protected function tearDown(): void
    {
        Harness::cleanup();
    }

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    private static function index(string $name, string $sync, TriggerLevel $level = TriggerLevel::Statement, bool $description = true, string $brandWeight = 'B', bool $fuzzyBrand = true): IndexDefinition
    {
        $b = IndexDefinition::builder($name)
            ->fromQuery('SELECT p.id, p.name, p.description, b.name AS brand, p.price, p.in_stock FROM fz_product p JOIN fz_brand b ON b.id = p.brand_id')
            ->watch('fz_product')
            ->watch('fz_brand', 'SELECT id FROM fz_product WHERE brand_id = :id')
            ->field('name', 'A', fuzzy: true)
            ->field('brand', $brandWeight, fuzzy: $fuzzyBrand)
            ->filter('price', 'int')
            ->language('english')
            ->sync($sync);
        if ($description) {
            $b->field('description', 'D');
        } else {
            $b->filter('in_stock', 'bool');
        }
        if ($sync !== 'manual') {
            $b->triggerLevel($level);
        }

        return $b->build();
    }

    /** @param list<IndexDefinition> $indexes */
    private function f(array $indexes, string $schema): Fuzzphony
    {
        return new Fuzzphony(new PostgresEngine($this->c, 'public', $schema, searchLog: true), new \Fuzzphony\Core\Registry\IndexRegistry($indexes));
    }

    /** @return iterable<string, array{string, TriggerLevel, string}> */
    public static function modes(): iterable
    {
        foreach (['public', 'fz_h_own'] as $schema) {
            yield "queue, {$schema}" => ['queue', TriggerLevel::Statement, $schema];
            yield "queue row, {$schema}" => ['queue', TriggerLevel::Row, $schema];
            yield "trigger statement, {$schema}" => ['trigger', TriggerLevel::Statement, $schema];
            yield "trigger row, {$schema}" => ['trigger', TriggerLevel::Row, $schema];
        }
    }

    #[DataProvider('modes')]
    public function testApplyDropApply(string $sync, TriggerLevel $level, string $schema): void
    {
        $a = self::index('alpha', $sync, $level);
        $b = self::index('beta', 'queue');
        $f = $this->f([$a, $b], $schema);
        $f->schema()->apply($this->c);
        $f->reindex('alpha');
        $f->reindex('beta');
        $f->schema()->apply($this->c);
        $installed = Harness::catalog($this->c);

        $engine = new PostgresEngine($this->c, 'public', $schema, searchLog: true);
        $engine->dropSchema($a)->apply($this->c);
        $engine->dropSchema($a)->apply($this->c); // a second drop is a no-op
        $left = implode("\n", array_merge(...array_values(Harness::catalog($this->c))));
        self::assertStringNotContainsString('alpha', $left, 'nothing of the dropped index is left');
        self::assertSame([], Harness::problems($f, 'beta'), 'the other index over the same tables is untouched');
        $this->c->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        (new Worker($engine))->runOnce([$b]);
        self::assertSame([900], $f->in('beta')->query('zebrafish')->get()->ids());
        $this->c->execute('DELETE FROM fz_product WHERE id = 900');
        (new Worker($engine))->runOnce([$b]);

        $f->schema()->apply($this->c);
        $f->reindex('alpha');
        self::assertSame($installed, Harness::catalog($this->c), 'apply after drop gives the first catalog');
        self::assertSame([], Harness::problems($f, 'alpha'));
        self::assertSame([], Harness::problems($f, 'beta'));
    }

    /** @return iterable<string, array{IndexDefinition, IndexDefinition}> */
    public static function changes(): iterable
    {
        yield 'a field becomes a filter' => [self::index('products', 'queue'), self::index('products', 'queue', description: false)];
        yield 'a weight changes' => [self::index('products', 'queue'), self::index('products', 'queue', brandWeight: 'C')];
        yield 'a field stops being fuzzy' => [self::index('products', 'trigger'), self::index('products', 'trigger', fuzzyBrand: false)];
        yield 'queue to trigger' => [self::index('products', 'queue'), self::index('products', 'trigger', TriggerLevel::Row)];
        yield 'trigger to queue' => [self::index('products', 'trigger', TriggerLevel::Row), self::index('products', 'queue')];
        yield 'statement to row level' => [self::index('products', 'trigger'), self::index('products', 'trigger', TriggerLevel::Row)];
        yield 'queue to manual' => [self::index('products', 'queue'), self::index('products', 'manual')];
        yield 'manual to queue row' => [self::index('products', 'manual'), self::index('products', 'queue', TriggerLevel::Row)];
    }

    #[DataProvider('changes')]
    public function testAChangedDefinitionAppliedThenReindexedEqualsAFreshInstall(IndexDefinition $old, IndexDefinition $new): void
    {
        $f = $this->f([$new], 'fz_h_own');
        $f->schema()->apply($this->c);
        $f->reindex('products');
        $f->schema()->apply($this->c);
        $fresh = Harness::catalog($this->c);

        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
        $f = $this->f([$old], 'fz_h_own');
        $f->schema()->apply($this->c);
        $f->reindex('products');

        $f = $this->f([$new], 'fz_h_own');
        $f->schema()->apply($this->c);
        $f->reindex('products');
        $f->schema()->apply($this->c);
        self::assertSame($fresh, Harness::catalog($this->c));
        self::assertSame([], Harness::problems($f, 'products'));
        self::assertContains(1, $f->in('products')->query('mouse')->get()->ids());

        $this->c->execute("INSERT INTO fz_product VALUES (900, 'Zebrafish lamp', 'aquarium', 1, 10, true, 1, now())");
        if ($new->sync->value === 'manual') {
            $f->refresh('products', [900]);
        } else {
            (new Worker(new PostgresEngine($this->c, 'public', 'fz_h_own', searchLog: true)))->runOnce([$new]);
        }
        self::assertSame([900], $f->in('products')->query('zebrafish')->get()->ids());
    }
}
