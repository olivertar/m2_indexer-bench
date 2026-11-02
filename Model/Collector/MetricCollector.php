<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Collector;

use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Model\Monitor\Clock;

/**
 * Metrics of one measured run (SPEC 5.3)
 *
 * SQL counts come from QueryCounterPlugin in this process. When the indexer forks workers
 * (MAGE_INDEXER_THREADS_COUNT > 1) their statements are not seen, so in parallel runs those metrics are null (n/a)
 * and only server-wide counters are kept, which include every connection.
 */
class MetricCollector
{
    public const WALL_TIME = 'wall_time_s';
    public const PEAK_MEMORY = 'peak_memory_mb';
    public const SQL_QUERIES = 'sql_queries';
    public const SQL_TIME = 'sql_time_s';
    public const SLOW_QUERIES = 'slow_queries';
    public const INDEX_ROWS = 'index_rows';

    /**
     * Metric code => server status variable(s) whose increase it is
     *
     * Innodb_rows_* exist on MySQL only (null on MariaDB). Handler_* exist on both; they count storage engine calls,
     * temporary tables included, so they are not row counts of the index tables.
     */
    public const STATUS_METRICS = [
        'rows_read' => 'Innodb_rows_read',
        'rows_inserted' => 'Innodb_rows_inserted',
        'rows_updated' => 'Innodb_rows_updated',
        'rows_deleted' => 'Innodb_rows_deleted',
        'tmp_tables' => 'Created_tmp_tables',
        'tmp_disk_tables' => 'Created_tmp_disk_tables',
        'row_lock_waits' => 'Innodb_row_lock_waits',
        'row_lock_time_ms' => 'Innodb_row_lock_time',
        'handler_read_rnd_next' => 'Handler_read_rnd_next',
        'handler_read' => [
            'Handler_read_first', 'Handler_read_key', 'Handler_read_last', 'Handler_read_next', 'Handler_read_prev',
            'Handler_read_rnd', 'Handler_read_rnd_next',
        ],
        'handler_write' => 'Handler_write',
        'handler_update' => 'Handler_update',
        'handler_delete' => 'Handler_delete',
    ];

    /**
     * @var float
     */
    private float $startedAt = 0.0;

    /**
     * @var array<string,float>
     */
    private array $statusBefore = [];

    /**
     * @var bool
     */
    private bool $parallel = false;

    /**
     * @param QueryCounter $counter
     * @param GlobalStatusReader $status
     * @param Clock $clock
     * @param ProcessMemory $memory
     * @param ReadOnlyConnection $connection
     * @param float $slowQuerySeconds statements at least this long count as slow
     */
    public function __construct(
        private readonly QueryCounter $counter,
        private readonly GlobalStatusReader $status,
        private readonly Clock $clock,
        private readonly ProcessMemory $memory,
        private readonly ReadOnlyConnection $connection,
        private readonly float $slowQuerySeconds = 1.0
    ) {
    }

    /**
     * Start measuring, right before the indexer runs
     *
     * @param bool $parallel whether the indexer forks workers
     * @return void
     */
    public function start(bool $parallel): void
    {
        $this->parallel = $parallel;
        $this->statusBefore = $this->status->read();
        $this->memory->resetPeak();
        if (!$parallel) {
            $this->counter->start($this->slowQuerySeconds);
        }
        $this->startedAt = $this->clock->monotonic();
    }

    /**
     * Stop measuring, right after the indexer ran, and return the metrics; null means not available
     *
     * @param string[] $indexTables live index tables, counted after the run
     * @return array<string,float|null>
     */
    public function stop(array $indexTables): array
    {
        $wallTime = $this->clock->monotonic() - $this->startedAt;
        $this->counter->stop();
        $statusAfter = $this->status->read();

        $metrics = [
            self::WALL_TIME => round($wallTime, 6),
            self::PEAK_MEMORY => round($this->memory->getPeakBytes() / 1048576, 6),
            self::SQL_QUERIES => $this->parallel ? null : (float)$this->counter->getQueries(),
            self::SQL_TIME => $this->parallel ? null : round($this->counter->getSeconds(), 6),
            self::SLOW_QUERIES => $this->parallel ? null : (float)$this->counter->getSlowQueries(),
        ];
        foreach (self::STATUS_METRICS as $code => $variables) {
            $metrics[$code] = $this->increase((array)$variables, $statusAfter);
        }
        $metrics[self::INDEX_ROWS] = (float)array_sum(array_map(
            fn (string $table): int => (int)$this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '', $table))
            ),
            $indexTables
        ));
        return $metrics;
    }

    /**
     * Increase of the sum of status variables; null when the server has none of them
     *
     * @param string[] $variables
     * @param array<string,float> $statusAfter
     * @return float|null
     */
    private function increase(array $variables, array $statusAfter): ?float
    {
        $increase = null;
        foreach ($variables as $variable) {
            if (isset($statusAfter[$variable], $this->statusBefore[$variable])) {
                $increase = ($increase ?? 0.0) + $statusAfter[$variable] - $this->statusBefore[$variable];
            }
        }
        return $increase;
    }
}
