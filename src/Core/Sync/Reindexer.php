<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Sync;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;

/**
 * @internal Batched, resumable reindex using keyset pagination over source ids.
 *
 * A full run builds next to the live index through the engine (Engine::beginRebuild()) and swaps
 * the result in; the documents the source no longer returns (orphans) go with the old index. A
 * failed run (a failed swap included) keeps its rebuild and releases the engine's rebuild lock
 * (Engine::abortRebuild() with $keepShadow), so a run resumed after the last printed id continues
 * it, in this process or another. The run writes the live index in place instead, as before 0.5,
 * with ReindexOptions::$inPlace, with $prune = false (a swap would drop what this session cannot
 * see), and when the engine says so (a role that cannot build next to the live index, a caller
 * transaction, or a resumed run without a rebuild to continue). In place, each batch is an
 * idempotent upsert, a full run finally removes the orphans and a resumed run leaves them alone.
 * A full run with $inPlace first discards a rebuild a failed run left behind
 * (Engine::discardLeftoverRebuild(), no session lock), so a later --from cannot continue it.
 *
 * Either way, what is dropped is relative to what THIS session sees (see ReindexOptions). A full
 * run that found no source row keeps the live index unless ReindexOptions::$pruneEmpty is set: an
 * empty source is far more likely a visibility problem than intent.
 */
final class Reindexer
{
    public function __construct(private readonly Engine $engine) {}

    public function run(IndexDefinition $index, ReindexOptions $options): ReindexResult
    {
        $resumed = $options->resumeAfter !== null;
        if ($options->inPlace && !$resumed) {
            // after this run changes the live index, a resume must not continue a leftover rebuild
            $this->engine->discardLeftoverRebuild($index);
        }
        if ($options->inPlace || !$options->prune || !$this->engine->beginRebuild($index, $resumed)) {
            return $this->inPlace($index, $options);
        }
        try {
            [$written, $seen] = $this->batches($index, $options, $this->engine->refreshShadow(...));
            if (!$resumed && $seen === 0 && !$options->pruneEmpty) {
                $this->engine->abortRebuild($index);

                return new ReindexResult($written, pruneSkippedEmptySource: true);
            }
            $this->engine->finishRebuild($index);
        } catch (\Throwable $e) {
            try {
                $this->engine->abortRebuild($index, keepShadow: true);
            } catch (\Throwable) {
                // the first failure is the one to report
            }

            throw $e;
        }
        $this->engine->recordReindex($index);

        return new ReindexResult($written, swapped: true);
    }

    private function inPlace(IndexDefinition $index, ReindexOptions $options): ReindexResult
    {
        [$written, $seen] = $this->batches($index, $options, $this->engine->refresh(...));
        if ($options->resumeAfter !== null || !$options->prune) {
            $result = new ReindexResult($written);
        } elseif ($seen === 0 && !$options->pruneEmpty) {
            $result = new ReindexResult($written, pruneSkippedEmptySource: true);
        } else {
            $result = new ReindexResult($written, $this->engine->pruneOrphans($index, $options->batchSize));
        }
        // a resumed run covers part of the source; an empty one is likely a visibility problem (as for pruning)
        if ($options->resumeAfter === null && ($seen > 0 || $options->pruneEmpty)) {
            $this->engine->recordReindex($index);
        }

        return $result;
    }

    /**
     * @param \Closure(IndexDefinition, list<int|string>): int $refresh
     *
     * @return array{int, int} documents written, source ids seen
     */
    private function batches(IndexDefinition $index, ReindexOptions $options, \Closure $refresh): array
    {
        $written = 0;
        $seen = 0;
        $after = $options->resumeAfter;
        do {
            $ids = $this->engine->sourceIds($index, $after, $options->batchSize);
            if ($ids === []) {
                break;
            }
            $seen += count($ids);
            $written += $refresh($index, $ids);
            $after = $ids[array_key_last($ids)];
            if ($options->onBatch !== null) {
                ($options->onBatch)($written, $after);
            }
        } while (count($ids) === $options->batchSize);

        return [$written, $seen];
    }
}
