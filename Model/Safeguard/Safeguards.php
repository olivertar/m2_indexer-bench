<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Safeguard;

use Magento\Framework\Exception\LocalizedException;

/**
 * Checks run before every measurement
 */
class Safeguards
{
    /**
     * Shown before every measurement: global status counters include the activity of every connection
     */
    public const TRAFFIC_WARNING = 'Measure without traffic: SHOW GLOBAL STATUS counters (rows, temporary tables, '
        . 'row locks, handlers) include every connection to the database server, not only this reindex.';

    /**
     * @param ProductionGuard $productionGuard
     * @param QuietnessCheck $quietnessCheck
     */
    public function __construct(
        private readonly ProductionGuard $productionGuard,
        private readonly QuietnessCheck $quietnessCheck
    ) {
    }

    /**
     * Problems that may distort the measurement of an indexer; abort on production or, when strict, on any problem
     *
     * @param string $indexerId
     * @param bool $strict
     * @return string[] warnings to show before measuring
     * @throws LocalizedException
     */
    public function check(string $indexerId, bool $strict): array
    {
        $this->productionGuard->assertAllowed();
        $problems = $this->quietnessCheck->findProblems($indexerId);
        if ($strict && $problems) {
            throw new LocalizedException(
                __('Not measuring %1 (--strict): %2', $indexerId, implode('; ', $problems))
            );
        }
        return $problems;
    }
}
