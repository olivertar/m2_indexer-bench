<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Collector;

use Orangecat\IndexerBench\Model\Collector\GlobalStatusReader;
use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\Collector\ProcessMemory;
use Orangecat\IndexerBench\Model\Collector\QueryCounter;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class MetricCollectorTest extends TestCase
{
    use WriteGuardAdapterTrait;

    /**
     * @var QueryCounter
     */
    private QueryCounter $counter;

    /**
     * @var bool
     */
    private bool $statusWithoutInnodbRows = false;

    protected function setUp(): void
    {
        $this->counter = new QueryCounter();
    }

    public function testSerialRunMeasuresEveryMetric(): void
    {
        $collector = $this->collector();

        $collector->start(false);
        $this->counter->record(0.5);
        $this->counter->record(1.5);
        $metrics = $collector->stop(['pfx_price', 'pfx_tier']);

        $this->assertSame(
            [
                'wall_time_s' => 12.5,
                'peak_memory_mb' => 64.0,
                'sql_queries' => 2.0,
                'sql_time_s' => 2.0,
                'slow_queries' => 1.0,
                'rows_read' => 900.0,
                'rows_inserted' => 300.0,
                'rows_updated' => 0.0,
                'rows_deleted' => 300.0,
                'tmp_tables' => 4.0,
                'tmp_disk_tables' => 1.0,
                'row_lock_waits' => 0.0,
                'row_lock_time_ms' => 0.0,
                'handler_read_rnd_next' => 5000.0,
                'handler_read' => 6200.0,
                'handler_write' => 320.0,
                'handler_update' => 0.0,
                'handler_delete' => 300.0,
                'index_rows' => 350.0,
            ],
            $metrics
        );
        $this->assertFalse($this->counter->isActive());
    }

    public function testParallelRunHasNoProcessSqlCounts(): void
    {
        $collector = $this->collector();

        $collector->start(true);
        $this->counter->record(0.5);
        $metrics = $collector->stop([]);

        $this->assertFalse($this->counter->isActive());
        $this->assertSame(
            ['sql_queries' => null, 'sql_time_s' => null, 'slow_queries' => null, 'index_rows' => 0.0],
            array_intersect_key($metrics, array_flip(['sql_queries', 'sql_time_s', 'slow_queries', 'index_rows']))
        );
        $this->assertSame(900.0, $metrics['rows_read']);
    }

    public function testCountersMissingOnTheServerAreNotAvailable(): void
    {
        // MariaDB has no Innodb_rows_* variables
        $this->statusWithoutInnodbRows = true;
        $collector = $this->collector();

        $collector->start(false);
        $metrics = $collector->stop([]);

        $this->assertSame(
            [null, null, null, null],
            [$metrics['rows_read'], $metrics['rows_inserted'], $metrics['rows_updated'], $metrics['rows_deleted']]
        );
        $this->assertSame(320.0, $metrics['handler_write']);
    }

    /**
     * Collector over fake clock, memory, status counters and index tables
     *
     * @return MetricCollector
     */
    private function collector(): MetricCollector
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('monotonic')->willReturnOnConsecutiveCalls(100.0, 112.5);
        $memory = $this->createStub(ProcessMemory::class);
        $memory->method('getPeakBytes')->willReturn(64 * 1024 * 1024);
        $status = $this->createStub(GlobalStatusReader::class);
        $readings = [
            [
                'Innodb_rows_read' => 100.0, 'Innodb_rows_inserted' => 0.0, 'Innodb_rows_updated' => 5.0,
                'Innodb_rows_deleted' => 0.0, 'Created_tmp_tables' => 10.0, 'Created_tmp_disk_tables' => 2.0,
                'Innodb_row_lock_waits' => 7.0, 'Innodb_row_lock_time' => 40.0, 'Handler_read_rnd_next' => 0.0,
                'Handler_read_key' => 50.0, 'Handler_read_next' => 10.0, 'Handler_read_first' => 0.0,
                'Handler_write' => 1000.0, 'Handler_update' => 2.0, 'Handler_delete' => 0.0,
            ],
            [
                'Innodb_rows_read' => 1000.0, 'Innodb_rows_inserted' => 300.0, 'Innodb_rows_updated' => 5.0,
                'Innodb_rows_deleted' => 300.0, 'Created_tmp_tables' => 14.0, 'Created_tmp_disk_tables' => 3.0,
                'Innodb_row_lock_waits' => 7.0, 'Innodb_row_lock_time' => 40.0, 'Handler_read_rnd_next' => 5000.0,
                'Handler_read_key' => 1150.0, 'Handler_read_next' => 110.0, 'Handler_read_first' => 0.0,
                'Handler_write' => 1320.0, 'Handler_update' => 2.0, 'Handler_delete' => 300.0,
            ],
        ];
        if ($this->statusWithoutInnodbRows) {
            $readings = array_map(
                static fn (array $reading): array => array_filter(
                    $reading,
                    static fn (string $name): bool => !str_starts_with($name, 'Innodb_rows_'),
                    ARRAY_FILTER_USE_KEY
                ),
                $readings
            );
        }
        $status->method('read')->willReturnOnConsecutiveCalls(...$readings);
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            static fn (string $sql): string => match ($sql) {
                'SELECT COUNT(*) FROM `pfx_price`' => '300',
                'SELECT COUNT(*) FROM `pfx_tier`' => '50',
                default => throw new \LogicException($sql),
            }
        )));

        return new MetricCollector($this->counter, $status, $clock, $memory, $connection, 1.0);
    }
}
