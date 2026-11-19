<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Hardening;

use Fuzzphony\Core\Database\Connection;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A concurrent index build cancelled midway leaves an INVALID index (its catalog entry is committed before the build waits for
 * the writers): the doctor reports it, and the next apply drops it and builds it again (IF NOT EXISTS alone would keep it).
 */
#[Group('hardening')]
final class InvalidIndexRepairTest extends TestCase
{
    private Connection $c;

    protected function setUp(): void
    {
        $this->c = Harness::fresh();
        Harness::fixtures($this->c);
    }

    /** @return iterable<string, array{string, string}> */
    public static function indexes(): iterable
    {
        yield 'sidecar full-text index' => ['fuzzphony_products', 'fuzzphony_products__tsv', 'USING gin (tsv)'];
        yield 'vocabulary trigram index' => ['fuzzphony_products__vocab', 'fuzzphony_products__vocab_trgm', 'USING gin (word public.gin_trgm_ops)'];
        yield 'vocabulary prefix index' => ['fuzzphony_products__vocab', 'fuzzphony_products__vocab_prefix', '(word text_pattern_ops)'];
    }

    #[DataProvider('indexes')]
    public function testTheNextApplyRebuildsAnIndexACancelledConcurrentBuildLeftInvalid(string $table, string $name, string $definition): void
    {
        $f = Harness::fuzzphony($this->c, [Indexes::products('queue')]);
        $f->schema()->apply($this->c);
        $f->reindex('products');
        self::assertNotNull($this->c->fetchValue('SELECT to_regclass(:n)', ['n' => $name]), 'the expected index name');

        $this->c->execute(sprintf('DROP INDEX %s', $name));
        $writer = PostgresTestCase::connect();
        $writer->execute('BEGIN');
        $writer->execute(sprintf('LOCK TABLE %s IN ROW EXCLUSIVE MODE', $table));
        $this->c->execute("SET statement_timeout = '300ms'");
        try {
            $this->c->execute(sprintf('CREATE INDEX CONCURRENTLY %s ON %s %s', $name, $table, $definition));
            self::fail('the build must wait for the writer');
        } catch (\Throwable) {
        } finally {
            $this->c->execute('RESET statement_timeout');
            $writer->execute('ROLLBACK');
        }
        self::assertSame([$name], Harness::invalidIndexes($this->c));
        self::assertNotSame([], array_filter(Harness::errors($f, 'products'), static fn(string $e): bool => str_contains($e, $name) && str_contains($e, 'INVALID')), 'the doctor names the INVALID index');

        $f->schema()->apply($this->c);

        self::assertSame([], Harness::invalidIndexes($this->c));
        self::assertNotNull($this->c->fetchValue('SELECT to_regclass(:n)', ['n' => $name]));
        self::assertSame([], Harness::problems($f, 'products'));
    }

    public function testAMissingVocabularyIndexIsAnError(): void
    {
        $f = Harness::fuzzphony($this->c, [Indexes::products('queue')]);
        $f->schema()->apply($this->c);
        $f->reindex('products');
        $this->c->execute('DROP INDEX fuzzphony_products__vocab_trgm');

        self::assertSame(['Error Vocabulary: Index fuzzphony_products__vocab_trgm is missing, so typo lookups and suggestions read the whole vocabulary.'], Harness::problems($f, 'products'));
    }
}
