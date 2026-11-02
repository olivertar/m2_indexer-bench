<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;

/**
 * Canonical, streamed dump of an index table: same data, same bytes, whatever the physical row order
 *
 * The table is read in batches ordered by its canonical key, each batch starting after the last key read (keyset
 * pagination), so it is never loaded whole and every batch uses the key index.
 */
class CanonicalDumper
{
    /**
     * @param ReadOnlyConnection $connection
     * @param RowSerializer $serializer
     * @param File $file
     * @param int $batchSize rows per query
     */
    public function __construct(
        private readonly ReadOnlyConnection $connection,
        private readonly RowSerializer $serializer,
        private readonly File $file,
        private readonly int $batchSize = 10000
    ) {
    }

    /**
     * Dump a table, optionally to a file, and return its checksum
     *
     * @param string $table physical table name, prefix included
     * @param string[] $keyColumns canonical order; must cover a unique key and hold no NULL
     * @param string|null $filePath
     * @return TableDump
     * @throws \InvalidArgumentException when the key does not identify rows
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function dump(string $table, array $keyColumns, ?string $filePath = null): TableDump
    {
        $columns = $this->serializer->columnOrder($this->columns($table, $keyColumns), $keyColumns);
        $select = sprintf(
            'SELECT %s FROM %s',
            implode(', ', array_map([$this, 'quote'], $columns)),
            $this->quote($table)
        );
        $order = sprintf(
            ' ORDER BY %s LIMIT %d',
            implode(', ', array_map([$this, 'quote'], $keyColumns)),
            $this->batchSize
        );

        $handle = $filePath === null ? null : $this->open($filePath);
        $hash = hash_init('md5');
        $header = $this->serializer->header($columns, $keyColumns);
        $this->write($hash, $handle, $header);

        $rows = 0;
        $last = null;
        do {
            [$where, $bind] = $last === null ? ['', []] : $this->after($keyColumns, $last);
            $batch = $this->connection->fetchAll($select . $where . $order, $bind);
            foreach ($batch as $row) {
                $this->write($hash, $handle, $this->serializer->line($row));
            }
            $rows += count($batch);
            $last = $batch ? end($batch) : null;
        } while (count($batch) === $this->batchSize);

        if ($handle !== null) {
            $this->file->fileClose($handle);
        }
        return new TableDump($table, $rows, hash_final($hash), $filePath);
    }

    /**
     * Columns to dump, after checking that the key identifies the rows
     *
     * Auto-increment columns outside the key are left out: core and optimized indexers number rows differently.
     *
     * @param string $table
     * @param string[] $keyColumns
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function columns(string $table, array $keyColumns): array
    {
        $nullable = [];
        $columns = [];
        foreach ($this->connection->fetchAll('SHOW COLUMNS FROM ' . $this->quote($table)) as $column) {
            $name = (string)$column['Field'];
            // A surrogate auto-increment ID depends on insert order, not on the indexed data
            $autoIncrement = str_contains((string)($column['Extra'] ?? ''), 'auto_increment');
            if ($autoIncrement && !in_array($name, $keyColumns, true)) {
                continue;
            }
            $columns[] = $name;
            if ($column['Null'] !== 'NO') {
                $nullable[] = $name;
            }
        }

        if (!$keyColumns || array_diff($keyColumns, $columns)) {
            throw new \InvalidArgumentException(sprintf(
                'Canonical key of %s must be columns of the table: %s',
                $table,
                implode(', ', $keyColumns)
            ));
        }
        // The core declares nullable columns in unique keys (catalogrule_product.customer_group_id). A NULL would
        // break the keyset pagination and allow duplicate keys, so they are accepted only while they hold none.
        foreach (array_intersect($keyColumns, $nullable) as $column) {
            if ($this->connection->fetchCol(
                sprintf('SELECT 1 FROM %s WHERE %s IS NULL LIMIT 1', $this->quote($table), $this->quote($column))
            )) {
                throw new \InvalidArgumentException(
                    sprintf('Canonical key of %s has NULL values in %s', $table, $column)
                );
            }
        }
        if (!$this->coversUniqueKey($table, $keyColumns)) {
            throw new \InvalidArgumentException(sprintf(
                'Canonical key of %s (%s) does not cover a unique key: rows could be skipped',
                $table,
                implode(', ', $keyColumns)
            ));
        }
        return $columns;
    }

    /**
     * Whether the key columns include every column of a unique index
     *
     * @param string $table
     * @param string[] $keyColumns
     * @return bool
     */
    private function coversUniqueKey(string $table, array $keyColumns): bool
    {
        $unique = [];
        foreach ($this->connection->fetchAll('SHOW INDEX FROM ' . $this->quote($table)) as $index) {
            if ((string)$index['Non_unique'] === '0') {
                $unique[(string)$index['Key_name']][] = (string)$index['Column_name'];
            }
        }
        foreach ($unique as $indexColumns) {
            if (!array_diff($indexColumns, $keyColumns)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Condition selecting the rows after a key: (a > ?) OR (a = ? AND b > ?) ...
     *
     * Written out instead of a row constructor so that every server version uses the key index.
     *
     * @param string[] $keyColumns
     * @param array<string,mixed> $last
     * @return array{string,array<int,mixed>}
     */
    private function after(array $keyColumns, array $last): array
    {
        $terms = [];
        $bind = [];
        foreach ($keyColumns as $position => $column) {
            $parts = [];
            foreach (array_slice($keyColumns, 0, $position) as $previous) {
                $parts[] = $this->quote($previous) . ' = ?';
                $bind[] = $last[$previous];
            }
            $parts[] = $this->quote($column) . ' > ?';
            $bind[] = $last[$column];
            $terms[] = '(' . implode(' AND ', $parts) . ')';
        }
        return [' WHERE ' . implode(' OR ', $terms), $bind];
    }

    /**
     * Quote an identifier, rejecting anything but letters, digits and underscores
     *
     * @param string $identifier
     * @return string
     * @throws \InvalidArgumentException
     */
    private function quote(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }

    /**
     * Open the dump file, creating its directory
     *
     * @param string $filePath
     * @return resource
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function open(string $filePath)
    {
        $this->file->createDirectory($this->file->getParentDirectory($filePath));
        return $this->file->fileOpen($filePath, 'w');
    }

    /**
     * Add a line to the checksum and to the file
     *
     * @param \HashContext $hash
     * @param resource|null $handle
     * @param string $line
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function write(\HashContext $hash, $handle, string $line): void
    {
        hash_update($hash, $line);
        if ($handle !== null) {
            $this->file->fileWrite($handle, $line);
        }
    }
}
