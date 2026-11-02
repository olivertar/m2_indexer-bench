<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Collector;

/**
 * SQL statements run by this process while a measurement is active
 *
 * Shared instance fed by QueryCounterPlugin. Inactive by default, so the plugin costs one boolean check outside
 * benchmarks. Forked indexer workers count in their own copy, which is lost: see MetricCollector.
 */
class QueryCounter
{
    /**
     * @var bool
     */
    private bool $active = false;

    /**
     * @var float
     */
    private float $slowThreshold = 1.0;

    /**
     * @var int
     */
    private int $queries = 0;

    /**
     * @var float
     */
    private float $seconds = 0.0;

    /**
     * @var int
     */
    private int $slowQueries = 0;

    /**
     * Reset the counts and start counting
     *
     * @param float $slowThreshold seconds from which a statement counts as slow
     * @return void
     */
    public function start(float $slowThreshold): void
    {
        $this->slowThreshold = $slowThreshold;
        $this->queries = 0;
        $this->seconds = 0.0;
        $this->slowQueries = 0;
        $this->active = true;
    }

    /**
     * Stop counting, keeping the counts
     *
     * @return void
     */
    public function stop(): void
    {
        $this->active = false;
    }

    /**
     * Whether statements are being counted
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Count one statement
     *
     * @param float $seconds
     * @return void
     */
    public function record(float $seconds): void
    {
        if (!$this->active) {
            return;
        }
        $this->queries++;
        $this->seconds += $seconds;
        if ($seconds >= $this->slowThreshold) {
            $this->slowQueries++;
        }
    }

    /**
     * Statements counted
     *
     * @return int
     */
    public function getQueries(): int
    {
        return $this->queries;
    }

    /**
     * Total seconds of the statements counted
     *
     * @return float
     */
    public function getSeconds(): float
    {
        return $this->seconds;
    }

    /**
     * Statements counted that reached the slow threshold
     *
     * @return int
     */
    public function getSlowQueries(): int
    {
        return $this->slowQueries;
    }
}
