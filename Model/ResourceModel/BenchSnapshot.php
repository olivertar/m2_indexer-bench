<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Orangecat\IndexerBench\Model\Snapshot\TableDump;

/**
 * Checksums of canonical snapshots, one row per table and one "*" row for the global checksum
 */
class BenchSnapshot extends AbstractDb
{
    public const TABLE = 'orangecat_indexer_bench_snapshot';

    /**
     * table_name of the global checksum row
     */
    public const GLOBAL_TABLE = '*';

    /**
     * @inheritdoc
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, 'snapshot_id');
    }

    /**
     * Store the snapshot of a label, replacing the previous snapshot with that label
     *
     * @param string $indexerId
     * @param string $label
     * @param TableDump[] $dumps
     * @param string $globalMd5
     * @return void
     * @throws \Throwable when the snapshot cannot be stored
     */
    public function replace(string $indexerId, string $label, array $dumps, string $globalMd5): void
    {
        $rows = [[
            'indexer_id' => $indexerId,
            'label' => $label,
            'table_name' => self::GLOBAL_TABLE,
            'row_count' => array_sum(array_map(static fn (TableDump $dump): int => $dump->rows, $dumps)),
            'md5' => $globalMd5,
            'file_path' => null,
        ]];
        foreach ($dumps as $dump) {
            $rows[] = [
                'indexer_id' => $indexerId,
                'label' => $label,
                'table_name' => $dump->table,
                'row_count' => $dump->rows,
                'md5' => $dump->md5,
                'file_path' => $dump->filePath,
            ];
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->delete(
                $this->getMainTable(),
                ['indexer_id = ?' => $indexerId, 'label = ?' => $label]
            );
            $connection->insertMultiple($this->getMainTable(), $rows);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Snapshot of a label by table name, "*" for the global checksum; empty when there is none
     *
     * @param string $indexerId
     * @param string $label
     * @return array<string,array{rows:int,md5:string,file_path:string|null}>
     */
    public function loadLabel(string $indexerId, string $label): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->getMainTable(), ['table_name', 'row_count', 'md5', 'file_path'])
            ->where('indexer_id = ?', $indexerId)
            ->where('label = ?', $label)
            ->order('table_name');
        $snapshot = [];
        foreach ($connection->fetchAll($select) as $row) {
            $snapshot[(string)$row['table_name']] = [
                'rows' => (int)$row['row_count'],
                'md5' => (string)$row['md5'],
                'file_path' => $row['file_path'] === null ? null : (string)$row['file_path'],
            ];
        }
        return $snapshot;
    }

    /**
     * DB adapter of the resource
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException('No database connection for ' . self::TABLE);
        }
        return $connection;
    }
}
