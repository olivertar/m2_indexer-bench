<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Report;

use Orangecat\IndexerBench\Model\Report\MetricOrder;
use PHPUnit\Framework\TestCase;

class MetricOrderTest extends TestCase
{
    public function testCollectorMetricsGoInSpecOrderIndexRowsLastThenUnknownByCode(): void
    {
        $codes = [
            'custom_b', 'custom_a', 'index_rows', 'peak_memory_mb', 'sql_queries', 'tmp_disk_tables', 'wall_time_s',
        ];

        $this->assertSame(
            ['wall_time_s', 'peak_memory_mb', 'sql_queries', 'tmp_disk_tables', 'index_rows', 'custom_a', 'custom_b'],
            (new MetricOrder())->sort($codes)
        );
    }
}
