<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

/**
 * A run as read back from orangecat_indexer_bench_run, with its metrics
 */
class StoredRun
{
    /**
     * @param int $runId
     * @param string $indexerId
     * @param string $label
     * @param string $mode
     * @param string $kind
     * @param int $repeatIndex
     * @param bool $warmup
     * @param bool $profiled
     * @param string $status ok|failed
     * @param string $startedAt UTC datetime
     * @param array<string,mixed>|null $fingerprint
     * @param array<string,float|null> $metrics
     * @param string|null $notes
     * @param int|null $sampleSize
     * @param int|null $seed
     * @param string|null $error error of a failed run
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $indexerId,
        public readonly string $label,
        public readonly string $mode,
        public readonly string $kind,
        public readonly int $repeatIndex,
        public readonly bool $warmup,
        public readonly bool $profiled,
        public readonly string $status,
        public readonly string $startedAt,
        public readonly ?array $fingerprint,
        public readonly array $metrics,
        public readonly ?string $notes = null,
        public readonly ?int $sampleSize = null,
        public readonly ?int $seed = null,
        public readonly ?string $error = null
    ) {
    }
}
