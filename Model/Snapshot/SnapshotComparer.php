<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\ResourceModel\BenchSnapshot;

/**
 * Compare the stored snapshots of two labels of an indexer and list the first differing rows
 */
class SnapshotComparer
{
    /**
     * @param BenchSnapshot $storage
     * @param SnapshotDiff $diff
     * @param AdapterPool $adapterPool
     * @param DirectoryList $directoryList
     * @param File $file
     */
    public function __construct(
        private readonly BenchSnapshot $storage,
        private readonly SnapshotDiff $diff,
        private readonly AdapterPool $adapterPool,
        private readonly DirectoryList $directoryList,
        private readonly File $file
    ) {
    }

    /**
     * Compare two snapshots; the baseline is the reference
     *
     * @param string $indexerId
     * @param string $baselineLabel
     * @param string $candidateLabel
     * @param int $limit differing rows listed per table
     * @return SnapshotComparison
     * @throws \InvalidArgumentException when a label has no snapshot
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function compare(
        string $indexerId,
        string $baselineLabel,
        string $candidateLabel,
        int $limit = 10
    ): SnapshotComparison {
        $baseline = $this->load($indexerId, $baselineLabel);
        $candidate = $this->load($indexerId, $candidateLabel);
        $global = BenchSnapshot::GLOBAL_TABLE;
        $matches = $baseline[$global]['md5'] === $candidate[$global]['md5'];
        $candidateRows = $candidate[$global]['rows'];

        $tables = [];
        if (!$matches) {
            unset($baseline[$global], $candidate[$global]);
            $names = array_unique(array_merge(array_keys($baseline), array_keys($candidate)));
            sort($names);
            foreach ($names as $table) {
                $tables[$table] = $this->compareTable(
                    $indexerId,
                    $table,
                    $baseline[$table] ?? null,
                    $candidate[$table] ?? null,
                    $baselineLabel,
                    $candidateLabel,
                    $limit
                );
            }
        }

        return new SnapshotComparison(
            $baselineLabel,
            $candidateLabel,
            $matches,
            $candidateRows,
            $tables
        );
    }

    /**
     * Compare one table of both snapshots
     *
     * @param string $indexerId
     * @param string $table
     * @param array{rows:int,md5:string,file_path:string|null}|null $baseline
     * @param array{rows:int,md5:string,file_path:string|null}|null $candidate
     * @param string $baselineLabel
     * @param string $candidateLabel
     * @param int $limit
     * @return TableComparison
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function compareTable(
        string $indexerId,
        string $table,
        ?array $baseline,
        ?array $candidate,
        string $baselineLabel,
        string $candidateLabel,
        int $limit
    ): TableComparison {
        if ($baseline === null || $candidate === null) {
            return new TableComparison(
                $table,
                $baseline['rows'] ?? null,
                $candidate['rows'] ?? null,
                false,
                [],
                'only in ' . ($baseline === null ? $candidateLabel : $baselineLabel)
            );
        }
        if ($baseline['md5'] === $candidate['md5']) {
            return new TableComparison($table, $baseline['rows'], $candidate['rows'], true);
        }

        $baselineFile = $this->absolute($baseline['file_path']);
        $candidateFile = $this->absolute($candidate['file_path']);
        if ($baselineFile === null || $candidateFile === null
            || !$this->file->isExists($baselineFile) || !$this->file->isExists($candidateFile)
        ) {
            return new TableComparison(
                $table,
                $baseline['rows'],
                $candidate['rows'],
                false,
                [],
                'dump file missing: take the snapshots again to list the rows'
            );
        }
        $keyColumns = count($this->adapterPool->get($indexerId)->getCanonicalOrder($table));
        return new TableComparison(
            $table,
            $baseline['rows'],
            $candidate['rows'],
            false,
            $this->diff->firstDifferences($baselineFile, $candidateFile, $keyColumns, $limit)
        );
    }

    /**
     * Absolute path of a dump stored relative to var/
     *
     * @param string|null $relativePath
     * @return string|null
     */
    private function absolute(?string $relativePath): ?string
    {
        return $relativePath === null
            ? null
            : $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . $relativePath;
    }

    /**
     * Stored snapshot of a label
     *
     * @param string $indexerId
     * @param string $label
     * @return array<string,array{rows:int,md5:string,file_path:string|null}>
     * @throws \InvalidArgumentException when there is none
     */
    private function load(string $indexerId, string $label): array
    {
        $snapshot = $this->storage->loadLabel($indexerId, $label);
        if (!isset($snapshot[BenchSnapshot::GLOBAL_TABLE])) {
            throw new \InvalidArgumentException(sprintf(
                'No snapshot of %s labelled "%s": bin/magento indexer-bench:snapshot -i %1$s -l %2$s',
                $indexerId,
                $label
            ));
        }
        return $snapshot;
    }
}
