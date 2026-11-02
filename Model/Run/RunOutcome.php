<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

/**
 * Stored result of one measurement of a series
 */
class RunOutcome
{
    /**
     * @param int $runId
     * @param int $repeatIndex
     * @param bool $warmup
     * @param array<string,float|null> $metrics
     * @param string|null $error null for a successful run
     * @param string|null $profilePath directory of the profiler reports relative to var/, null when not profiled
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $repeatIndex,
        public readonly bool $warmup,
        public readonly array $metrics,
        public readonly ?string $error,
        public readonly ?string $profilePath = null
    ) {
    }
}
