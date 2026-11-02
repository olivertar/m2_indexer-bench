<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\ResourceModel\BenchSnapshot;

/**
 * Dump every index table of an indexer to var/indexer-bench/<indexer>/<label>/ and store the checksums
 */
class SnapshotTaker
{
    /**
     * Directory of the dumps, relative to var/
     */
    public const BASE_DIR = 'indexer-bench';

    /**
     * Extension of a table dump
     */
    private const EXTENSION = '.tsv';

    /**
     * A label is a directory name: no separator, no leading dot
     */
    private const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * @param AdapterPool $adapterPool
     * @param CanonicalDumper $dumper
     * @param BenchSnapshot $storage
     * @param DirectoryList $directoryList
     * @param File $file
     */
    public function __construct(
        private readonly AdapterPool $adapterPool,
        private readonly CanonicalDumper $dumper,
        private readonly BenchSnapshot $storage,
        private readonly DirectoryList $directoryList,
        private readonly File $file
    ) {
    }

    /**
     * Take the snapshot of a label, replacing a previous snapshot with that label
     *
     * @param string $indexerId
     * @param string $label
     * @return SnapshotResult
     * @throws \InvalidArgumentException for an unknown indexer, a bad label or a key that does not identify rows
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function take(string $indexerId, string $label): SnapshotResult
    {
        if (!preg_match(self::LABEL_PATTERN, $label)) {
            throw new \InvalidArgumentException(
                sprintf('Label must be letters, digits, ".", "_" or "-", not starting with "." or "-": "%s"', $label)
            );
        }
        $adapter = $this->adapterPool->get($indexerId);
        $relativeDir = implode('/', [self::BASE_DIR, $indexerId, $label]);
        $dir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . $relativeDir;
        $this->removeDumps($dir);
        $this->file->createDirectory($dir);

        $dumps = [];
        foreach ($adapter->getIndexTables() as $table) {
            $fileName = $table . self::EXTENSION;
            $dump = $this->dumper->dump($table, $adapter->getCanonicalOrder($table), $dir . '/' . $fileName);
            $dumps[$table] = new TableDump($table, $dump->rows, $dump->md5, $relativeDir . '/' . $fileName);
        }
        ksort($dumps);
        $dumps = array_values($dumps);

        $globalMd5 = $this->globalMd5($dumps);
        $this->storage->replace($indexerId, $label, $dumps, $globalMd5);
        return new SnapshotResult($dumps, $globalMd5);
    }

    /**
     * Remove the table dumps of a previous snapshot; a table may be gone, e.g. after a dimensions mode change
     *
     * @param string $dir
     * @return void
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function removeDumps(string $dir): void
    {
        if (!$this->file->isExists($dir)) {
            return;
        }
        foreach ($this->file->readDirectory($dir) as $path) {
            if (str_ends_with($path, self::EXTENSION)) {
                $this->file->deleteFile($path);
            }
        }
    }

    /**
     * Checksum of the table checksums: one line "table, rows, md5" per table, sorted by table
     *
     * @param TableDump[] $dumps sorted by table name
     * @return string
     */
    private function globalMd5(array $dumps): string
    {
        $hash = hash_init('md5');
        foreach ($dumps as $dump) {
            hash_update($hash, sprintf("%s\t%d\t%s\n", $dump->table, $dump->rows, $dump->md5));
        }
        return hash_final($hash);
    }
}
