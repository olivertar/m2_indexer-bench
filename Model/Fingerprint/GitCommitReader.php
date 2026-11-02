<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Fingerprint;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Commit checked out in the Magento root, read from .git without running git
 */
class GitCommitReader
{
    /**
     * @param File $file
     * @param string $root Magento root
     */
    public function __construct(
        private readonly File $file,
        private readonly string $root = BP
    ) {
    }

    /**
     * Full commit hash, null without a repository or without commits
     *
     * @return string|null
     */
    public function getCommit(): ?string
    {
        try {
            $head = $this->read('HEAD');
            if ($head === null || !str_starts_with($head, 'ref: ')) {
                return $this->hash($head);
            }
            $ref = substr($head, 5);
            return $this->hash($this->read($ref)) ?? $this->packed($ref);
        } catch (FileSystemException) {
            return null;
        }
    }

    /**
     * Commit of a ref in .git/packed-refs
     *
     * @param string $ref
     * @return string|null
     * @throws FileSystemException
     */
    private function packed(string $ref): ?string
    {
        foreach (explode("\n", (string)$this->read('packed-refs')) as $line) {
            $parts = explode(' ', trim($line));
            if (count($parts) === 2 && $parts[1] === $ref) {
                return $this->hash($parts[0]);
            }
        }
        return null;
    }

    /**
     * Trimmed content of a file under .git, null when missing
     *
     * @param string $path
     * @return string|null
     * @throws FileSystemException
     */
    private function read(string $path): ?string
    {
        $file = $this->root . '/.git/' . $path;
        return $this->file->isFile($file) ? trim($this->file->fileGetContents($file)) : null;
    }

    /**
     * The value when it is a commit hash
     *
     * @param string|null $value
     * @return string|null
     */
    private function hash(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $value) ? $value : null;
    }
}
