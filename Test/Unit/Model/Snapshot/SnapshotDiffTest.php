<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Snapshot;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Snapshot\DiffLine;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotDiff;
use PHPUnit\Framework\TestCase;

class SnapshotDiffTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }

    public function testRowsAreMatchedByKeyNotByPosition(): void
    {
        $baseline = $this->file("#entity_id\tstore_id\tvalue\n1\t0\ta\n2\t0\tb\n10\t0\tc\n11\t0\td\n");
        $candidate = $this->file("#entity_id\tstore_id\tvalue\n2\t0\tB\n3\t0\tx\n10\t0\tc\n11\t0\td\n");

        $lines = (new SnapshotDiff(new File()))->firstDifferences($baseline, $candidate, 2, 10);

        $this->assertSame(
            [
                [DiffLine::MISSING, "1\t0", "1\t0\ta", null],
                [DiffLine::CHANGED, "2\t0", "2\t0\tb", "2\t0\tB"],
                [DiffLine::EXTRA, "3\t0", null, "3\t0\tx"],
            ],
            array_map(static fn (DiffLine $l): array => [$l->type, $l->key, $l->baseline, $l->candidate], $lines)
        );
    }

    public function testStopsAtTheLimitAndReportsDifferentColumns(): void
    {
        $baseline = $this->file("#id\tvalue\n1\ta\n2\tb\n3\tc\n");
        $candidate = $this->file("#id\tvalue\tnew\n1\ta\t0\n2\tb\t0\n3\tc\t0\n");

        $lines = (new SnapshotDiff(new File()))->firstDifferences($baseline, $candidate, 1, 2);

        $this->assertCount(2, $lines);
        $this->assertSame(
            [DiffLine::COLUMNS, '', "#id\tvalue", "#id\tvalue\tnew"],
            [$lines[0]->type, $lines[0]->key, $lines[0]->baseline, $lines[0]->candidate]
        );
        $this->assertSame(DiffLine::CHANGED, $lines[1]->type);
    }

    /**
     * @param string $contents
     * @return string
     */
    private function file(string $contents): string
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'bench-diff');
        file_put_contents($path, $contents);
        $this->files[] = $path;
        return $path;
    }
}
