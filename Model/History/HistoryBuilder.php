<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\History;

use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;

/**
 * Recent runs of an indexer with the median of one metric per label and its trend
 *
 * Label medians are taken over every comparable run of the label, as compare does, not only the listed ones.
 */
class HistoryBuilder
{
    /**
     * @param RunReader $runReader
     * @param Median $median
     */
    public function __construct(
        private readonly RunReader $runReader,
        private readonly Median $median
    ) {
    }

    /**
     * Build the history
     *
     * @param string $indexerId
     * @param int $limit runs to list
     * @param string $metric metric code
     * @return History
     */
    public function build(string $indexerId, int $limit, string $metric): History
    {
        $runs = $this->runReader->getRecentRuns($indexerId, $limit);
        $labels = array_values(array_unique(array_map(static fn (StoredRun $run): string => $run->label, $runs)));
        $summaries = [];
        // Previous median by kind: a partial reindex is only compared with partial ones, a full with full ones
        $previous = [];
        foreach ($labels as $label) {
            $comparable = $this->runReader->getComparableRuns($indexerId, $label);
            $kind = $comparable ? $comparable[0]->kind : '';
            $before = $previous[$kind] ?? null;
            $values = array_values(array_filter(
                array_map(static fn (StoredRun $run): ?float => $run->metrics[$metric] ?? null, $comparable),
                static fn (?float $value): bool => $value !== null
            ));
            $median = $this->median->of($values);
            $summaries[] = new LabelSummary(
                $label,
                count($comparable),
                $median,
                $values ? min($values) : null,
                $values ? max($values) : null,
                $median === null || !$before ? null : round(($median - $before) / $before * 100, 6)
            );
            $previous[$kind] = $median ?? $before;
        }
        return new History($runs, $summaries);
    }
}
