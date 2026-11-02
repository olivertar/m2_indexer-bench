<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Report;

use Orangecat\IndexerBench\Model\Collector\MetricCollector;

/**
 * Order in which reports list metrics
 */
class MetricOrder
{
    /**
     * The collector's metrics as SPEC 5.3 lists them, index_rows last, then unknown metrics by code
     *
     * @param string[] $codes
     * @return string[]
     */
    public function sort(array $codes): array
    {
        $known = $this->knownCodes();
        $unknown = array_diff($codes, $known);
        sort($unknown);
        return array_merge(array_values(array_intersect($known, $codes)), $unknown);
    }

    /**
     * Codes of the metrics the collector measures, in report order
     *
     * @return string[]
     */
    public function knownCodes(): array
    {
        return array_merge(
            [
                MetricCollector::WALL_TIME,
                MetricCollector::PEAK_MEMORY,
                MetricCollector::SQL_QUERIES,
                MetricCollector::SQL_TIME,
                MetricCollector::SLOW_QUERIES,
            ],
            array_keys(MetricCollector::STATUS_METRICS),
            [MetricCollector::INDEX_ROWS]
        );
    }
}
