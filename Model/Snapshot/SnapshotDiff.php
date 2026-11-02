<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Snapshot;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * First differences between two canonical dumps of the same table, matching rows by key
 *
 * Both files are sorted by key, so they are merged in one pass without loading them. Keys are compared numerically
 * when both values are numbers, as the database orders integer keys.
 */
class SnapshotDiff
{
    /**
     * Longest line read, in bytes
     */
    private const MAX_LINE = 16777216;

    /**
     * @param File $file
     */
    public function __construct(
        private readonly File $file
    ) {
    }

    /**
     * Up to $limit differences, in key order
     *
     * @param string $baselineFile
     * @param string $candidateFile
     * @param int $keyColumns number of leading columns forming the key
     * @param int $limit
     * @return DiffLine[]
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function firstDifferences(string $baselineFile, string $candidateFile, int $keyColumns, int $limit): array
    {
        $baseline = $this->file->fileOpen($baselineFile, 'r');
        $candidate = $this->file->fileOpen($candidateFile, 'r');
        $differences = [];
        try {
            $baselineHeader = $this->read($baseline);
            $candidateHeader = $this->read($candidate);
            if ($baselineHeader !== $candidateHeader) {
                $differences[] = new DiffLine(DiffLine::COLUMNS, '', $baselineHeader, $candidateHeader);
            }

            $left = $this->read($baseline);
            $right = $this->read($candidate);
            while (count($differences) < $limit && ($left !== null || $right !== null)) {
                if ($right === null || ($left !== null && $this->compare($left, $right, $keyColumns) < 0)) {
                    $key = $this->key((string)$left, $keyColumns);
                    $differences[] = new DiffLine(DiffLine::MISSING, $key, $left, null);
                    $left = $this->read($baseline);
                } elseif ($left === null || $this->compare($left, $right, $keyColumns) > 0) {
                    $differences[] = new DiffLine(DiffLine::EXTRA, $this->key($right, $keyColumns), null, $right);
                    $right = $this->read($candidate);
                } else {
                    if ($left !== $right) {
                        $differences[] = new DiffLine(DiffLine::CHANGED, $this->key($left, $keyColumns), $left, $right);
                    }
                    $left = $this->read($baseline);
                    $right = $this->read($candidate);
                }
            }
        } finally {
            $this->file->fileClose($baseline);
            $this->file->fileClose($candidate);
        }
        return $differences;
    }

    /**
     * Order of two lines by key
     *
     * @param string $left
     * @param string $right
     * @param int $keyColumns
     * @return int
     */
    private function compare(string $left, string $right, int $keyColumns): int
    {
        $leftKey = explode("\t", $this->key($left, $keyColumns));
        $rightKey = explode("\t", $this->key($right, $keyColumns));
        foreach ($leftKey as $position => $value) {
            $other = $rightKey[$position] ?? '';
            $order = is_numeric($value) && is_numeric($other) ? $value <=> $other : strcmp($value, $other);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    /**
     * Key columns of a line
     *
     * @param string $line
     * @param int $keyColumns
     * @return string
     */
    private function key(string $line, int $keyColumns): string
    {
        return implode("\t", array_slice(explode("\t", $line), 0, $keyColumns));
    }

    /**
     * Next line without its end, null at the end of the file
     *
     * @param resource $handle
     * @return string|null
     * @throws FileSystemException
     */
    private function read($handle): ?string
    {
        if ($this->file->endOfFile($handle)) {
            return null;
        }
        try {
            return $this->file->fileReadLine($handle, self::MAX_LINE, "\n");
        } catch (FileSystemException) {
            // stream_get_line() returns false past the last line, before feof() turns true
            return null;
        }
    }
}
