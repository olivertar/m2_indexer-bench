<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * Canonical snapshot of every index table of an indexer
 */
class SnapshotResult
{
    /**
     * @param TableDump[] $dumps sorted by table name; file paths relative to var/
     * @param string $globalMd5
     */
    public function __construct(
        public readonly array $dumps,
        public readonly string $globalMd5
    ) {
    }

    /**
     * Rows of every table
     *
     * @return int
     */
    public function rows(): int
    {
        return array_sum(array_map(static fn (TableDump $dump): int => $dump->rows, $this->dumps));
    }
}
