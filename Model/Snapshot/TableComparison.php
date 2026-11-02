<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * Comparison of one index table between two snapshots
 */
class TableComparison
{
    /**
     * @param string $table
     * @param int|null $baselineRows null when the table is not in the baseline snapshot
     * @param int|null $candidateRows null when the table is not in the candidate snapshot
     * @param bool $matches
     * @param DiffLine[] $differences first differing rows, when both dumps could be read
     * @param string|null $note why the differences could not be listed
     */
    public function __construct(
        public readonly string $table,
        public readonly ?int $baselineRows,
        public readonly ?int $candidateRows,
        public readonly bool $matches,
        public readonly array $differences = [],
        public readonly ?string $note = null
    ) {
    }
}
