<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Compare;

/**
 * One side of a comparison: a single run, or the median of the comparable runs of a label
 */
class RunSelection
{
    /**
     * @param string $label
     * @param int[] $runIds
     * @param bool $byLabel whether the selection is the median of a label
     * @param array<string,float|null> $metrics
     * @param array<string,mixed>|null $fingerprint
     * @param string $kind full|partial
     * @param int|null $sampleSize
     * @param int|null $seed
     */
    public function __construct(
        public readonly string $label,
        public readonly array $runIds,
        public readonly bool $byLabel,
        public readonly array $metrics,
        public readonly ?array $fingerprint,
        public readonly string $kind,
        public readonly ?int $sampleSize = null,
        public readonly ?int $seed = null
    ) {
    }

    /**
     * E.g. "core (median of 5 runs)" or "#19 (boost)"
     *
     * @return string
     */
    public function describe(): string
    {
        if (!$this->byLabel) {
            return sprintf('#%d (%s)', $this->runIds[0], $this->label);
        }
        $count = count($this->runIds);
        return sprintf('%s (median of %d %s)', $this->label, $count, $count === 1 ? 'run' : 'runs');
    }
}
