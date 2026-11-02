<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * Verdict between the snapshots of two labels
 */
class SnapshotComparison
{
    public const MATCH = 'MATCH';
    public const DIFFER = 'DIFFER';

    /**
     * @param string $baselineLabel
     * @param string $candidateLabel
     * @param bool $matches whether the global checksums are equal
     * @param int $candidateRows rows of every table of the candidate
     * @param TableComparison[] $tables by table name
     */
    public function __construct(
        public readonly string $baselineLabel,
        public readonly string $candidateLabel,
        private readonly bool $matches,
        private readonly int $candidateRows,
        public readonly array $tables
    ) {
    }

    /**
     * Whether both snapshots hold exactly the same index data
     *
     * @return bool
     */
    public function matches(): bool
    {
        return $this->matches;
    }

    /**
     * MATCH or DIFFER
     *
     * @return string
     */
    public function verdict(): string
    {
        return $this->matches ? self::MATCH : self::DIFFER;
    }

    /**
     * Rows of every table of the candidate
     *
     * @return int
     */
    public function candidateRows(): int
    {
        return $this->candidateRows;
    }
}
