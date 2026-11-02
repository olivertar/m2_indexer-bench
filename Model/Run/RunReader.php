<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Reads of stored benchmark runs and their metrics
 */
class RunReader
{
    /**
     * @param ReadOnlyConnection $connection
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection
    ) {
    }

    /**
     * One run, null when it does not exist
     *
     * @param int $runId
     * @return StoredRun|null
     */
    public function getRun(int $runId): ?StoredRun
    {
        return $this->read('run_id = ?', [$runId])[0] ?? null;
    }

    /**
     * Runs of a label a median is taken over: successful, neither warm-up nor profiled, in repeat order
     *
     * @param string $indexerId
     * @param string $label
     * @return StoredRun[]
     */
    public function getComparableRuns(string $indexerId, string $label): array
    {
        return $this->read(
            "indexer_id = ? AND label = ? AND status = 'ok' AND warmup = 0 AND profiled = 0",
            [$indexerId, $label]
        );
    }

    /**
     * Last stored run of any indexer, null when there is none
     *
     * @return StoredRun|null
     */
    public function getLatestRun(): ?StoredRun
    {
        $runId = $this->connection->fetchOne(sprintf(
            'SELECT MAX(run_id) FROM %s',
            $this->connection->getTableName(BenchRun::TABLE)
        ));
        return $runId === null || $runId === false ? null : $this->getRun((int)$runId);
    }

    /**
     * Indexers with stored runs: run count and UTC start of the last one, by indexer id
     *
     * @return array<string,array{runs:int,last_started_at:string}>
     */
    public function getIndexers(): array
    {
        $indexers = [];
        foreach ($this->connection->fetchAll(sprintf(
            'SELECT indexer_id, COUNT(*) AS runs, MAX(started_at) AS last_started_at FROM %s '
            . 'GROUP BY indexer_id ORDER BY indexer_id',
            $this->connection->getTableName(BenchRun::TABLE)
        )) as $row) {
            $indexers[(string)$row['indexer_id']] = [
                'runs' => (int)$row['runs'],
                'last_started_at' => (string)$row['last_started_at'],
            ];
        }
        return $indexers;
    }

    /**
     * Labels of an indexer's runs, in the order they were first used
     *
     * @param string $indexerId
     * @return string[]
     */
    public function getLabels(string $indexerId): array
    {
        return array_map('strval', $this->connection->fetchCol(
            sprintf(
                'SELECT label FROM %s WHERE indexer_id = ? GROUP BY label ORDER BY MIN(run_id)',
                $this->connection->getTableName(BenchRun::TABLE)
            ),
            [$indexerId]
        ));
    }

    /**
     * Last runs of an indexer, whatever their status, in run order
     *
     * @param string $indexerId
     * @param int $limit
     * @return StoredRun[]
     */
    public function getRecentRuns(string $indexerId, int $limit): array
    {
        $runIds = $this->connection->fetchCol(
            sprintf(
                'SELECT run_id FROM %s WHERE indexer_id = ? ORDER BY run_id DESC LIMIT %d',
                $this->connection->getTableName(BenchRun::TABLE),
                max(1, $limit)
            ),
            [$indexerId]
        );
        if (!$runIds) {
            return [];
        }
        return $this->read(
            sprintf('run_id IN (%s)', implode(', ', array_fill(0, count($runIds), '?'))),
            array_map('intval', $runIds)
        );
    }

    /**
     * Runs matching a condition, with their metrics, ordered by run ID
     *
     * @param string $where
     * @param array<int,int|string> $bind
     * @return StoredRun[]
     */
    private function read(string $where, array $bind): array
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT * FROM %s WHERE %s ORDER BY run_id',
                $this->connection->getTableName(BenchRun::TABLE),
                $where
            ),
            $bind
        );
        if (!$rows) {
            return [];
        }
        $metrics = $this->metrics(array_map(static fn (array $row): int => (int)$row['run_id'], $rows));

        return array_map(fn (array $row): StoredRun => new StoredRun(
            (int)$row['run_id'],
            (string)$row['indexer_id'],
            (string)$row['label'],
            (string)$row['mode'],
            (string)$row['kind'],
            (int)$row['repeat_index'],
            (bool)$row['warmup'],
            (bool)$row['profiled'],
            (string)$row['status'],
            (string)$row['started_at'],
            $row['fingerprint'] === null ? null : (array)json_decode((string)$row['fingerprint'], true),
            $metrics[(int)$row['run_id']] ?? [],
            $row['notes'] === null ? null : (string)$row['notes'],
            $row['sample_size'] === null ? null : (int)$row['sample_size'],
            $row['seed'] === null ? null : (int)$row['seed'],
            $row['error'] === null ? null : (string)$row['error']
        ), $rows);
    }

    /**
     * Metrics by run ID, by metric code
     *
     * @param int[] $runIds
     * @return array<int,array<string,float|null>>
     */
    private function metrics(array $runIds): array
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT run_id, metric_code, value FROM %s WHERE run_id IN (%s) ORDER BY run_id, metric_code',
                $this->connection->getTableName(BenchRun::METRIC_TABLE),
                implode(', ', array_fill(0, count($runIds), '?'))
            ),
            $runIds
        );
        $metrics = [];
        foreach ($rows as $row) {
            $metrics[(int)$row['run_id']][(string)$row['metric_code']] =
                $row['value'] === null ? null : (float)$row['value'];
        }
        return $metrics;
    }
}
