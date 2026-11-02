<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

/**
 * One finished measurement, as stored in orangecat_indexer_bench_run
 */
class RunRecord
{
    public const KIND_FULL = 'full';
    public const KIND_PARTIAL = 'partial';

    /**
     * @param string $indexerId
     * @param string $label
     * @param string $mode baseline|candidate
     * @param string $kind full|partial
     * @param int $repeatIndex position in the series, from 0
     * @param bool $warmup
     * @param \DateTimeImmutable $startedAt
     * @param \DateTimeImmutable $finishedAt
     * @param string|null $error null for a successful run
     * @param array<string,mixed> $fingerprint
     * @param string|null $notes
     * @param int|null $sampleSize partial runs: number of IDs
     * @param int|null $seed partial runs: sampling seed
     * @param bool $profiled
     */
    public function __construct(
        public readonly string $indexerId,
        public readonly string $label,
        public readonly string $mode,
        public readonly string $kind,
        public readonly int $repeatIndex,
        public readonly bool $warmup,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $finishedAt,
        public readonly ?string $error,
        public readonly array $fingerprint,
        public readonly ?string $notes = null,
        public readonly ?int $sampleSize = null,
        public readonly ?int $seed = null,
        public readonly bool $profiled = false
    ) {
    }
}
