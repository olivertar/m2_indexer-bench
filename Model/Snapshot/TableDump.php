<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * Checksum of the canonical dump of one table
 */
class TableDump
{
    /**
     * @param string $table
     * @param int $rows
     * @param string $md5 of the header and every row line
     * @param string|null $filePath dump file, null when only the checksum was computed
     */
    public function __construct(
        public readonly string $table,
        public readonly int $rows,
        public readonly string $md5,
        public readonly ?string $filePath = null
    ) {
    }
}
