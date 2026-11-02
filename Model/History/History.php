<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\History;

use Orangecat\IndexerBench\Model\Run\StoredRun;

/**
 * Recent runs of an indexer and a summary of their labels
 */
class History
{
    /**
     * @param StoredRun[] $runs in run order
     * @param LabelSummary[] $labels in order of first appearance among the runs
     */
    public function __construct(
        public readonly array $runs,
        public readonly array $labels
    ) {
    }
}
