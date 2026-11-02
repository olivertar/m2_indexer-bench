<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Compare;

use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;

/**
 * Resolve a --baseline/--candidate reference: digits are a run ID, anything else a label
 */
class RunSelector
{
    /**
     * @param RunReader $reader
     * @param Median $median
     */
    public function __construct(
        private readonly RunReader $reader,
        private readonly Median $median
    ) {
    }

    /**
     * Run or label median a reference points to
     *
     * @param string $indexerId
     * @param string $reference run ID or label
     * @return RunSelection
     * @throws \InvalidArgumentException when it points to nothing comparable
     */
    public function select(string $indexerId, string $reference): RunSelection
    {
        return ctype_digit($reference)
            ? $this->selectRun($indexerId, (int)$reference)
            : $this->selectLabel($indexerId, $reference);
    }

    /**
     * One stored run, refused when it would distort the comparison
     *
     * @param string $indexerId
     * @param int $runId
     * @return RunSelection
     * @throws \InvalidArgumentException
     */
    private function selectRun(string $indexerId, int $runId): RunSelection
    {
        $run = $this->reader->getRun($runId);
        $problem = match (true) {
            $run === null => 'does not exist',
            $run->indexerId !== $indexerId => sprintf('measured %s, not %s', $run->indexerId, $indexerId),
            $run->status !== BenchRun::STATUS_OK => 'failed',
            $run->warmup => 'is a warm-up',
            $run->profiled => 'ran with the profiler: its metrics include the profiler overhead',
            default => null,
        };
        if ($run === null || $problem !== null) {
            throw new \InvalidArgumentException(sprintf('Run #%d %s', $runId, $problem));
        }
        return new RunSelection(
            $run->label,
            [$run->runId],
            false,
            $run->metrics,
            $run->fingerprint,
            $run->kind,
            $run->sampleSize,
            $run->seed
        );
    }

    /**
     * Median of the comparable runs of a label
     *
     * @param string $indexerId
     * @param string $label
     * @return RunSelection
     * @throws \InvalidArgumentException
     */
    private function selectLabel(string $indexerId, string $label): RunSelection
    {
        $runs = $this->reader->getComparableRuns($indexerId, $label);
        if (!$runs) {
            throw new \InvalidArgumentException(sprintf(
                'No comparable run of %s labelled "%s" (successful, not warm-up, not profiled)',
                $indexerId,
                $label
            ));
        }
        $first = $runs[0];
        return new RunSelection(
            $label,
            array_map(static fn (StoredRun $run): int => $run->runId, $runs),
            true,
            $this->median->ofMetrics(array_map(static fn (StoredRun $run): array => $run->metrics, $runs)),
            $first->fingerprint,
            $first->kind,
            $first->sampleSize,
            $first->seed
        );
    }
}
