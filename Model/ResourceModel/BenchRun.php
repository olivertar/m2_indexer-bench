<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\ResourceModel;

use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Orangecat\IndexerBench\Model\Run\RunRecord;

/**
 * Writes of benchmark runs and their metrics
 */
class BenchRun extends AbstractDb
{
    public const TABLE = 'orangecat_indexer_bench_run';
    public const METRIC_TABLE = 'orangecat_indexer_bench_metric';

    public const STATUS_OK = 'ok';
    public const STATUS_FAILED = 'failed';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'run_id');
    }

    /**
     * Store a finished run and its metrics in one transaction
     *
     * @param RunRecord $run
     * @param array<string,float|null> $metrics null stored as NULL (n/a)
     * @return int run ID
     * @throws \Throwable when the run cannot be stored
     */
    public function saveRun(RunRecord $run, array $metrics): int
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->insert($this->getMainTable(), [
                'indexer_id' => $run->indexerId,
                'label' => $run->label,
                'mode' => $run->mode,
                'kind' => $run->kind,
                'sample_size' => $run->sampleSize,
                'seed' => $run->seed,
                'repeat_index' => $run->repeatIndex,
                'warmup' => (int)$run->warmup,
                'profiled' => (int)$run->profiled,
                'started_at' => $this->toUtc($run->startedAt),
                'finished_at' => $this->toUtc($run->finishedAt),
                'status' => $run->error === null ? self::STATUS_OK : self::STATUS_FAILED,
                'error' => $run->error,
                'fingerprint' => json_encode($run->fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'notes' => $run->notes,
            ]);
            $runId = (int)$connection->lastInsertId($this->getMainTable());
            $rows = [];
            foreach ($metrics as $code => $value) {
                $rows[] = [
                    'run_id' => $runId,
                    'metric_code' => $code,
                    'value' => $value === null ? null : number_format($value, 6, '.', ''),
                ];
            }
            if ($rows) {
                $connection->insertMultiple($this->getTable(self::METRIC_TABLE), $rows);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $runId;
    }

    /**
     * DB adapter of the resource; the MySQL one, for lastInsertId()
     *
     * @return Mysql
     * @throws \RuntimeException
     */
    private function connection(): Mysql
    {
        $connection = $this->getConnection();
        if (!$connection instanceof Mysql) {
            throw new \RuntimeException('No database connection for ' . self::TABLE);
        }
        return $connection;
    }

    /**
     * Format a moment as a UTC datetime, the timezone Magento stores dates in
     *
     * @param \DateTimeImmutable $moment
     * @return string
     */
    private function toUtc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
