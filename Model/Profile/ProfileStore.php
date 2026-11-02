<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Profile;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Keeps the profiler reports of a run under var/indexer-bench/<indexer>/<run_id>/
 */
class ProfileStore
{
    private const BASE_DIR = 'indexer-bench';

    /**
     * @param DirectoryList $directoryList
     * @param File $file
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file
    ) {
    }

    /**
     * Copy the reports next to the run; the originals stay where the profiler keeps and prunes them
     *
     * @param string $indexerId
     * @param int $runId
     * @param string[] $reports absolute paths
     * @return string|null directory relative to var/, null when there is no report
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function store(string $indexerId, int $runId, array $reports): ?string
    {
        if (!$reports) {
            return null;
        }
        $relativeDir = implode('/', [self::BASE_DIR, $indexerId, (string)$runId]);
        $dir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . $relativeDir;
        $this->file->createDirectory($dir);
        foreach ($reports as $report) {
            $this->file->copy($report, $dir . '/' . ltrim((string)strrchr('/' . $report, '/'), '/'));
        }
        return $relativeDir;
    }
}
