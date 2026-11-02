<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Compare;

use Orangecat\IndexerBench\Model\Compare\MetricComparator;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetricComparatorTest extends TestCase
{
    #[DataProvider('verdictProvider')]
    public function testVerdictDependsOnTheChangeAgainstTheThreshold(
        string $code,
        ?float $baseline,
        ?float $candidate,
        ?float $delta,
        string $verdict
    ): void {
        $comparator = new MetricComparator(new MetricOrder());

        $comparison = $comparator->compare([$code => $baseline], [$code => $candidate], 10.0)[0];

        $this->assertSame([$code, $baseline, $candidate, $delta, $verdict], [
            $comparison->code, $comparison->baseline, $comparison->candidate, $comparison->deltaPct,
            $comparison->verdict,
        ]);
    }

    public function testMetricsFollowTheReportOrderWhateverTheStoredOrder(): void
    {
        // RunReader returns metrics by code; unknown metrics go last, by code
        $metrics = [
            'custom_b' => 1.0,
            'custom_a' => 1.0,
            'index_rows' => 1.0,
            'peak_memory_mb' => 1.0,
            'sql_queries' => 1.0,
            'tmp_disk_tables' => 1.0,
            'wall_time_s' => 1.0,
        ];

        $codes = array_map(
            static fn ($comparison): string => $comparison->code,
            (new MetricComparator(new MetricOrder()))->compare($metrics, $metrics, 10.0)
        );

        $this->assertSame(
            ['wall_time_s', 'peak_memory_mb', 'sql_queries', 'tmp_disk_tables', 'index_rows', 'custom_a', 'custom_b'],
            $codes
        );
    }

    /**
     * @return array<string,array{string,float|null,float|null,float|null,string}>
     */
    public static function verdictProvider(): array
    {
        return [
            'improved' => ['wall_time_s', 200.0, 50.0, -75.0, 'improved'],
            'regression' => ['wall_time_s', 100.0, 111.0, 11.0, 'regression'],
            'within threshold' => ['wall_time_s', 100.0, 109.0, 9.0, 'same'],
            'at threshold' => ['wall_time_s', 100.0, 110.0, 10.0, 'same'],
            'zero to zero' => ['slow_queries', 0.0, 0.0, 0.0, 'same'],
            'zero to some' => ['tmp_disk_tables', 0.0, 2.0, null, 'regression'],
            'some to zero' => ['tmp_disk_tables', 4.0, 0.0, -100.0, 'improved'],
            'not available' => ['sql_queries', null, 12.0, null, 'n/a'],
            'index rows equal' => ['index_rows', 8.0, 8.0, 0.0, 'same'],
            'index rows changed' => ['index_rows', 8.0, 4.0, -50.0, 'changed'],
        ];
    }
}
