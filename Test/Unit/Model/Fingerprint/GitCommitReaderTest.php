<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Fingerprint;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Fingerprint\GitCommitReader;
use PHPUnit\Framework\TestCase;

class GitCommitReaderTest extends TestCase
{
    private const COMMIT = '3f6c2a9e1d4b8c7a6f5e4d3c2b1a09f8e7d6c5b4';

    /**
     * @var string
     */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bench-git-' . uniqid();
        mkdir($this->root . '/.git/refs/heads', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testBranchHeadIsResolvedFromItsRefFile(): void
    {
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root . '/.git/refs/heads/main', self::COMMIT . "\n");

        $this->assertSame(self::COMMIT, (new GitCommitReader(new File(), $this->root))->getCommit());
    }

    public function testPackedRefAndDetachedHeadAreResolved(): void
    {
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/heads/release\n");
        file_put_contents(
            $this->root . '/.git/packed-refs',
            "# pack-refs with: peeled\n" . self::COMMIT . " refs/heads/release\n"
        );
        $reader = new GitCommitReader(new File(), $this->root);

        $this->assertSame(self::COMMIT, $reader->getCommit());

        file_put_contents($this->root . '/.git/HEAD', strrev(self::COMMIT) . "\n");
        $this->assertSame(strrev(self::COMMIT), $reader->getCommit());
    }

    public function testRepositoryWithoutCommitsOrWithoutGitHasNone(): void
    {
        $reader = new GitCommitReader(new File(), $this->root);
        file_put_contents($this->root . '/.git/HEAD', "ref: refs/heads/main\n");

        $this->assertNull($reader->getCommit());
        $this->assertNull((new GitCommitReader(new File(), $this->root . '/missing'))->getCommit());
    }
}
