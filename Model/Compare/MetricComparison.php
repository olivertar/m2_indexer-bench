<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Compare;

/**
 * One metric of the baseline against the candidate
 */
class MetricComparison
{
    public const IMPROVED = 'improved';
    public const REGRESSION = 'regression';
    public const SAME = 'same';
    public const CHANGED = 'changed';
    public const NOT_AVAILABLE = 'n/a';

    /**
     * @param string $code
     * @param float|null $baseline
     * @param float|null $candidate
     * @param float|null $deltaPct change in %, null when not available or from zero
     * @param string $verdict
     */
    public function __construct(
        public readonly string $code,
        public readonly ?float $baseline,
        public readonly ?float $candidate,
        public readonly ?float $deltaPct,
        public readonly string $verdict
    ) {
    }
}
