<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Fingerprint;

/**
 * Facts of the machine and process environment running the benchmark
 */
class HostInfo
{
    /**
     * Same variable as Magento\Indexer\Model\ProcessManager::THREADS_COUNT
     */
    public const THREADS_ENV = 'MAGE_INDEXER_THREADS_COUNT';

    /**
     * Host name
     *
     * @return string
     */
    public function getHostname(): string
    {
        return (string)gethostname();
    }

    /**
     * Indexer worker processes requested through the environment, null when not set
     *
     * @return int|null
     */
    public function getThreadsCount(): ?int
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the indexer itself reads it with getenv()
        $value = getenv(self::THREADS_ENV);
        return $value === false || $value === '' ? null : (int)$value;
    }
}
