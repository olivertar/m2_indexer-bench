<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\History;

/**
 * One metric over the comparable runs of a label
 */
class LabelSummary
{
    /**
     * @param string $label
     * @param int $runCount comparable runs: successful, neither warm-up nor profiled
     * @param float|null $median
     * @param float|null $min
     * @param float|null $max
     * @param float|null $deltaPct change of the median from the previous label of the same kind
     *     (full or partial), null for the first or when unknown
     */
    public function __construct(
        public readonly string $label,
        public readonly int $runCount,
        public readonly ?float $median,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly ?float $deltaPct
    ) {
    }
}
