<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Compare;

use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\Report\MetricOrder;

/**
 * Change of each metric from baseline to candidate, judged against a threshold
 *
 * Every metric is a cost (lower is better) except index_rows, which only has to stay the same.
 */
class MetricComparator
{
    /**
     * @param MetricOrder $metricOrder
     */
    public function __construct(
        private readonly MetricOrder $metricOrder
    ) {
    }

    /**
     * Compare the metrics of both sides
     *
     * @param array<string,float|null> $baseline
     * @param array<string,float|null> $candidate
     * @param float $thresholdPct change beyond which a metric improved or regressed
     * @return MetricComparison[]
     */
    public function compare(array $baseline, array $candidate, float $thresholdPct): array
    {
        $comparisons = [];
        foreach ($this->metricOrder->sort(array_map('strval', array_keys($baseline + $candidate))) as $code) {
            $comparisons[] = $this->compareMetric(
                (string)$code,
                $baseline[$code] ?? null,
                $candidate[$code] ?? null,
                $thresholdPct
            );
        }
        return $comparisons;
    }

    /**
     * Compare one metric
     *
     * @param string $code
     * @param float|null $baseline
     * @param float|null $candidate
     * @param float $thresholdPct
     * @return MetricComparison
     */
    private function compareMetric(
        string $code,
        ?float $baseline,
        ?float $candidate,
        float $thresholdPct
    ): MetricComparison {
        if ($baseline === null || $candidate === null) {
            return new MetricComparison($code, $baseline, $candidate, null, MetricComparison::NOT_AVAILABLE);
        }
        if ($baseline == 0.0) {
            // No percentage from zero: any cost appearing is a regression
            return new MetricComparison(
                $code,
                $baseline,
                $candidate,
                $candidate == 0.0 ? 0.0 : null,
                $candidate == 0.0 ? MetricComparison::SAME : $this->worse($code)
            );
        }
        $delta = round(($candidate - $baseline) / $baseline * 100, 6);
        $verdict = match (true) {
            $code === MetricCollector::INDEX_ROWS => $delta == 0.0 ? MetricComparison::SAME : MetricComparison::CHANGED,
            $delta > $thresholdPct => MetricComparison::REGRESSION,
            $delta < -$thresholdPct => MetricComparison::IMPROVED,
            default => MetricComparison::SAME,
        };
        return new MetricComparison($code, $baseline, $candidate, $delta, $verdict);
    }

    /**
     * Verdict of a metric that grew from zero
     *
     * @param string $code
     * @return string
     */
    private function worse(string $code): string
    {
        return $code === MetricCollector::INDEX_ROWS ? MetricComparison::CHANGED : MetricComparison::REGRESSION;
    }
}
