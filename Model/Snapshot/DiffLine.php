<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

/**
 * One difference between two canonical dumps
 */
class DiffLine
{
    public const MISSING = 'missing';
    public const EXTRA = 'extra';
    public const CHANGED = 'changed';
    public const COLUMNS = 'columns';

    /**
     * @param string $type missing (only in baseline), extra (only in candidate), changed or columns (headers differ)
     * @param string $key key columns of the row, tab-separated; empty for columns
     * @param string|null $baseline baseline line
     * @param string|null $candidate candidate line
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly ?string $baseline,
        public readonly ?string $candidate
    ) {
    }
}
