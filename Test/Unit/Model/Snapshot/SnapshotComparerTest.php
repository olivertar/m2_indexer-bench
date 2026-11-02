<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Snapshot;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\ResourceModel\BenchSnapshot;
use Orangecat\IndexerBench\Model\Snapshot\DiffLine;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotDiff;
use Orangecat\IndexerBench\Model\Snapshot\TableComparison;
use PHPUnit\Framework\TestCase;

class SnapshotComparerTest extends TestCase
{
    /**
     * @var array<string,array<string,array{rows:int,md5:string,file_path:string|null}>> snapshots by label
     */
    private array $snapshots = [];

    public function testSnapshotsWithTheSameGlobalChecksumMatchWithoutReadingDumps(): void
    {
        $this->snapshots['core'] = $this->snapshotOf(['pfx_price' => [10, 'aaa']], 'g1');
        $this->snapshots['boost'] = $this->snapshotOf(['pfx_price' => [10, 'aaa']], 'g1');
        $diff = $this->createMock(SnapshotDiff::class);
        $diff->expects($this->never())->method('firstDifferences');

        $comparison = $this->comparer($diff)->compare('catalog_product_price', 'core', 'boost');

        $this->assertTrue($comparison->matches());
        $this->assertSame(10, $comparison->candidateRows());
    }

    public function testDifferingTablesListTheirFirstDifferentRowsAndTablesOnOneSideAreReported(): void
    {
        $this->snapshots['core'] = $this->snapshotOf(
            ['pfx_price' => [10, 'aaa'], 'pfx_tier' => [2, 'ttt'], 'pfx_old' => [1, 'ooo']],
            'g1'
        );
        $this->snapshots['boost'] = $this->snapshotOf(
            ['pfx_price' => [10, 'bbb'], 'pfx_tier' => [2, 'ttt'], 'pfx_new' => [4, 'nnn']],
            'g2'
        );
        $line = new DiffLine(DiffLine::CHANGED, "5\t1", "5\t1\t10.00", "5\t1\t9.00");
        $diff = $this->createMock(SnapshotDiff::class);
        $diff->expects($this->once())->method('firstDifferences')
            ->with('/m/var/indexer-bench/aaa.tsv', '/m/var/indexer-bench/bbb.tsv', 2, 3)
            ->willReturn([$line]);

        $comparison = $this->comparer($diff)->compare('catalog_product_price', 'core', 'boost', 3);

        $this->assertSame('DIFFER', $comparison->verdict());
        $this->assertSame(
            [
                'pfx_new' => [null, 4, false, [], 'only in boost'],
                'pfx_old' => [1, null, false, [], 'only in core'],
                'pfx_price' => [10, 10, false, [$line], null],
                'pfx_tier' => [2, 2, true, [], null],
            ],
            array_map(
                static fn (TableComparison $t): array => [$t->baselineRows, $t->candidateRows, $t->matches,
                    $t->differences, $t->note],
                $comparison->tables
            )
        );
    }

    public function testDifferingTableWhoseDumpIsGoneSaysSo(): void
    {
        $this->snapshots['core'] = $this->snapshotOf(['pfx_price' => [10, 'aaa']], 'g1');
        $this->snapshots['boost'] = $this->snapshotOf(['pfx_price' => [10, 'bbb']], 'g2');
        $diff = $this->createMock(SnapshotDiff::class);
        $diff->expects($this->never())->method('firstDifferences');

        $table = $this->comparer($diff, false)->compare('catalog_product_price', 'core', 'boost')
            ->tables['pfx_price'];

        $this->assertSame('dump file missing: take the snapshots again to list the rows', $table->note);
    }

    public function testLabelWithoutSnapshotIsRejected(): void
    {
        $this->snapshots['core'] = $this->snapshotOf(['pfx_price' => [10, 'aaa']], 'g1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No snapshot of catalog_product_price labelled "boost"');
        $this->comparer($this->createStub(SnapshotDiff::class))->compare('catalog_product_price', 'core', 'boost');
    }

    /**
     * Stored snapshot rows for table => [rows, md5]
     *
     * @param array<string,array{int,string}> $tables
     * @param string $globalMd5
     * @return array<string,array{rows:int,md5:string,file_path:string|null}>
     */
    private function snapshotOf(array $tables, string $globalMd5): array
    {
        $snapshot = [
            '*' => [
                'rows' => array_sum(array_column($tables, 0)),
                'md5' => $globalMd5,
                'file_path' => null,
            ],
        ];
        foreach ($tables as $table => [$rows, $md5]) {
            $snapshot[$table] = ['rows' => $rows, 'md5' => $md5, 'file_path' => 'indexer-bench/' . $md5 . '.tsv'];
        }
        return $snapshot;
    }

    /**
     * Comparer over the snapshots of this test; dumps under /m/var
     *
     * @param SnapshotDiff $diff
     * @param bool $filesExist
     * @return SnapshotComparer
     */
    private function comparer(SnapshotDiff $diff, bool $filesExist = true): SnapshotComparer
    {
        $storage = $this->createStub(BenchSnapshot::class);
        $storage->method('loadLabel')->willReturnCallback(
            fn (string $indexerId, string $label): array => $this->snapshots[$label] ?? []
        );
        $adapter = $this->createStub(IndexerAdapterInterface::class);
        $adapter->method('getCanonicalOrder')->willReturn(['entity_id', 'website_id']);
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn('/m/var');
        $file = $this->createStub(File::class);
        $file->method('isExists')->willReturn($filesExist);

        return new SnapshotComparer(
            $storage,
            $diff,
            new AdapterPool(['catalog_product_price' => $adapter]),
            $directoryList,
            $file
        );
    }
}
