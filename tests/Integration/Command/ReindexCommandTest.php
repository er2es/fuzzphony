<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Command;

use Fuzzphony\Bundle\Command\ReindexCommand;
use Fuzzphony\Core\Support\Coerce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

final class ReindexCommandTest extends TestCase
{
    private CommandTestCase $context;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->context = new CommandTestCase();
        $this->context->applySchemaAndReindex();
        $this->context->connection->execute('DELETE FROM fz_product WHERE id = 4'); // manual sync: document 4 is now an orphan
        $this->tester = new CommandTester(new ReindexCommand($this->context->fuzzphony));
    }

    public function testAFullRunSwapsInTheRebuiltIndex(): void
    {
        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Built next to the live index and swapped in: searches never saw a partial index, and documents the source no longer returns went with the old one.', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testInPlaceRemovesOrphansAndSaysHowMany(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--in-place' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('1 orphaned document(s) removed', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testARoleThatCannotBuildNextToTheLiveIndexRebuildsInPlaceAndSaysSo(): void
    {
        $connection = $this->context->connection;
        $role = 'fz_reindexer_' . getmypid();
        $connection->execute(sprintf('DROP ROLE IF EXISTS %s', $role));
        $connection->execute(sprintf('CREATE ROLE %s', $role));
        try {
            $connection->execute(sprintf('GRANT SELECT ON fz_product, fz_brand TO %s', $role));
            $connection->execute(sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON fuzzphony_products, fuzzphony_meta TO %s', $role));
            $connection->execute(sprintf('SET ROLE %s', $role));
            try {
                $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);
            } finally {
                $connection->execute('RESET ROLE');
            }
        } finally {
            $connection->execute(sprintf('DROP OWNED BY %s', $role));
            $connection->execute(sprintf('DROP ROLE %s', $role));
        }

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('1 orphaned document(s) removed', $this->tester->getDisplay());
        self::assertStringContainsString("Rebuilt in place: this role cannot build the index next to the live one (it needs CREATE on Fuzzphony's schema and ownership of the index table), or fuzzphony:schema --apply has not run since the upgrade.", $this->tester->getDisplay());
        self::assertStringContainsString('The documents are indexed, but the vocabulary could not be rebuilt:', $this->tester->getDisplay());
        self::assertStringContainsString('fuzzphony:reindex --vocabulary', $this->tester->getDisplay());
        self::assertSame(4, $this->indexed());
    }

    public function testAFullRunReportsTheVocabulary(): void
    {
        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertMatchesRegularExpression('/\d+ words in the vocabulary \("did you mean"\)/', $this->tester->getDisplay());
        self::assertStringNotContainsString('could not be rebuilt', $this->tester->getDisplay());
    }

    public function testTheVocabularyOptionRebuildsOnlyTheVocabulary(): void
    {
        $this->context->connection->execute('TRUNCATE "fuzzphony_products__vocab"');

        $status = $this->tester->execute(['index' => 'products', '--vocabulary' => true], ['interactive' => false]);

        $display = $this->tester->getDisplay();
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertMatchesRegularExpression('/\d+ words in the vocabulary, in \d{1,3}\.\ds/', $display);
        self::assertStringNotContainsString('documents in', $display);
        self::assertStringNotContainsString('orphaned', $display);
        self::assertGreaterThan(0, Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM "fuzzphony_products__vocab"')));
        self::assertSame(5, $this->indexed(), 'no document was written: the orphan is still there');
    }

    public function testTheVocabularyOptionFailsLoudlyWhenItCannotRun(): void
    {
        $this->context->connection->execute('DROP TABLE "fuzzphony_products__vocab"');

        $status = $this->tester->execute(['index' => 'products', '--vocabulary' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('fuzzphony:schema --apply', $this->tester->getDisplay());
    }

    public function testPrintsProgressPerBatchAndASummary(): void
    {
        // Source rows 1, 2, 3, 5 (4 was deleted in setUp), two per batch.
        $status = $this->tester->execute(['index' => 'products', '--batch' => '2'], ['interactive' => false]);

        $display = $this->tester->getDisplay();
        self::assertSame(Command::SUCCESS, $status, $display);
        // The rate must be a positive number of documents per second (generous bounds so slow CI can't flake).
        self::assertMatchesRegularExpression('~^  2 documents, [1-9][\d,]*/s, last id 2 \(resume: --from=2\)$~m', $display);
        self::assertMatchesRegularExpression('~^  4 documents, [1-9][\d,]*/s, last id 5 \(resume: --from=5\)$~m', $display);
        // The elapsed time is a small number of seconds, not an epoch-sized sum.
        self::assertMatchesRegularExpression('~^  4 documents in \d{1,3}\.\ds$~m', $display);
    }

    public function testAResumedRunLeavesOrphansAlone(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--from' => '2'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('only removed by a full run', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed());
    }

    public function testNoPruneKeepsWhatTheSessionCannotSee(): void
    {
        $status = $this->tester->execute(['index' => 'products', '--no-prune' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Pruning skipped (--no-prune)', $this->tester->getDisplay());
        self::assertStringNotContainsString('orphaned document(s) removed', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed());
    }

    public function testASourceThatReturnsNothingIsNotPrunedWithoutPruneEmpty(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('source returned no rows for this session, so nothing was pruned', $this->tester->getDisplay());
        self::assertStringContainsString('--prune-empty', $this->tester->getDisplay());
        self::assertStringNotContainsString('Rebuilt in place:', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed());
    }

    public function testPruneEmptyWipesTheIndexOfAnEmptySource(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true, '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Built next to the live index and swapped in', $this->tester->getDisplay());
        self::assertSame(0, $this->indexed());
    }

    public function testPruneEmptyWithForceSkipsTheConfirmationEntirely(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true, '--force' => true], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringNotContainsString('Prune every document of an empty source?', $this->tester->getDisplay());
        self::assertSame(0, $this->indexed());
    }

    public function testPruneEmptyRefusesNonInteractivelyWithoutForce(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Refusing to prune an empty source without confirmation: pass --force in non-interactive runs.', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed(), 'nothing should have been pruned');
    }

    public function testPruneEmptyAsksAndProceedsOnYes(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');
        $this->tester->setInputs(['yes']);

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Prune every document of an empty source? (yes/no) [no]:', $this->tester->getDisplay());
        self::assertSame(0, $this->indexed());
    }

    public function testPruneEmptyAsksAndRefusesOnNo(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');
        $this->tester->setInputs(['no']);

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertStringContainsString('Prune every document of an empty source? (yes/no) [no]:', $this->tester->getDisplay());
        self::assertSame(5, $this->indexed(), 'nothing should have been pruned');
    }

    public function testPruneEmptyAsksAndDefaultsToNoOnEmptyAnswer(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');
        $this->tester->setInputs(['']);

        $status = $this->tester->execute(['index' => 'products', '--prune-empty' => true]);

        self::assertSame(Command::FAILURE, $status, $this->tester->getDisplay());
        self::assertSame(5, $this->indexed(), 'nothing should have been pruned');
    }

    public function testPruneEmptyExplainsWhatWillHappenBeforeAsking(): void
    {
        $this->context->connection->execute('DELETE FROM fz_product');

        $this->tester->execute(['index' => 'products', '--prune-empty' => true], ['interactive' => false]);

        self::assertStringContainsString(
            'This will remove every indexed document of any index whose source returns no row this run (row-level security, search_path, or the source is genuinely empty); an index with at least one source row is pruned as usual.',
            $this->tester->getDisplay(),
        );
    }

    public function testPruneEmptyIsOnlyAskedWhenTheOptionIsGiven(): void
    {
        $status = $this->tester->execute(['index' => 'products'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $this->tester->getDisplay());
        self::assertStringNotContainsString('Prune every document of an empty source?', $this->tester->getDisplay());
    }

    public function testCompletesIndexNames(): void
    {
        $completion = new CommandCompletionTester(new ReindexCommand($this->context->fuzzphony));

        self::assertSame(['products'], $completion->complete(['']));
    }

    private function indexed(): int
    {
        return Coerce::int($this->context->connection->fetchValue('SELECT count(*) FROM fuzzphony_products'));
    }
}
