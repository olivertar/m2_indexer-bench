<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

/**
 * Options of a series of full reindex measurements
 */
class RunRequest
{
    public const MODE_BASELINE = 'baseline';
    public const MODE_CANDIDATE = 'candidate';

    /**
     * @param string $indexerId
     * @param string $label groups the repeats; compare and history refer to it
     * @param string $mode baseline|candidate
     * @param int $repeat number of measurements, warm-up included
     * @param bool $warmup whether the first measurement is left out of the median
     * @param bool $skipReset reindex without invalidating the indexer first
     * @param string|null $notes
     * @param int[]|null $ids entity IDs of a partial run (reindexList); null for a full reindex
     * @param int|null $seed seed the IDs were sampled with, null when given explicitly
     * @param bool $profile record each repeat with the Magento profiler (mage-os/module-profiler)
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly string $indexerId,
        public readonly string $label,
        public readonly string $mode = self::MODE_BASELINE,
        public readonly int $repeat = 1,
        public readonly bool $warmup = false,
        public readonly bool $skipReset = false,
        public readonly ?string $notes = null,
        public readonly ?array $ids = null,
        public readonly ?int $seed = null,
        public readonly bool $profile = false
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A label is required');
        }
        if (!in_array($mode, [self::MODE_BASELINE, self::MODE_CANDIDATE], true)) {
            throw new \InvalidArgumentException(sprintf('Mode must be baseline or candidate, got "%s"', $mode));
        }
        if ($repeat < 1) {
            throw new \InvalidArgumentException('--repeat must be at least 1');
        }
        if ($warmup && $repeat < 2) {
            throw new \InvalidArgumentException('--warmup needs --repeat=2 or more');
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('A partial run needs at least one ID');
        }
    }

    /**
     * RunRecord::KIND_FULL or RunRecord::KIND_PARTIAL
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->ids === null ? RunRecord::KIND_FULL : RunRecord::KIND_PARTIAL;
    }
}
