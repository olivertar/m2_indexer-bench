<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Collector;

/**
 * Peak memory of this PHP process; forked indexer workers are not included
 */
class ProcessMemory
{
    /**
     * Start a new peak from the current usage
     *
     * @return void
     */
    public function resetPeak(): void
    {
        // The module requires PHP 8.2+ (composer.json), where this function exists
        // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.memory_reset_peak_usageFound
        memory_reset_peak_usage();
    }

    /**
     * Peak memory allocated from the system since the last reset
     *
     * @return int bytes
     */
    public function getPeakBytes(): int
    {
        return memory_get_peak_usage(true);
    }
}
