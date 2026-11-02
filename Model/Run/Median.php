<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

/**
 * Median of repeated measurements; null values (n/a) are left out
 */
class Median
{
    /**
     * Median of values, null when no value is available
     *
     * @param array<int,float|null> $values
     * @return float|null
     */
    public function of(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn (?float $value): bool => $value !== null));
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        sort($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * Median of each metric over a series of repeats, in the order metrics first appear
     *
     * @param array<int,array<string,float|null>> $repeats metrics of each repeat
     * @return array<string,float|null>
     */
    public function ofMetrics(array $repeats): array
    {
        $values = [];
        foreach ($repeats as $metrics) {
            foreach ($metrics as $code => $value) {
                $values[$code][] = $value;
            }
        }
        return array_map(fn (array $series): ?float => $this->of($series), $values);
    }
}
