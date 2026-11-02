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
use Orangecat\IndexerBench\Model\Snapshot\CanonicalDumper;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotTaker;
use Orangecat\IndexerBench\Model\Snapshot\TableDump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SnapshotTakerTest extends TestCase
{
    /**
     * @var array<int,array{string,string[],string|null}> dumper calls: table, key, file
     */
    private array $dumped = [];

    /**
     * @var array<int,array{string,string,TableDump[],string}> stored snapshots
     */
    private array $stored = [];

    /**
     * @var string[] file system calls
     */
    private array $fileCalls = [];

    public function testEveryTableIsDumpedToTheLabelDirectoryAndStoredWithTheGlobalChecksum(): void
    {
        $result = $this->taker()->take('catalog_product_price', 'core');

        $dir = '/m/var/indexer-bench/catalog_product_price/core/';
        $this->assertSame(
            [
                ['pfx_price_b', ['entity_id', 'website_id'], $dir . 'pfx_price_b.tsv'],
                ['pfx_price_a', ['entity_id'], $dir . 'pfx_price_a.tsv'],
            ],
            $this->dumped
        );
        $this->assertContains('createDirectory:' . rtrim($dir, '/'), $this->fileCalls);
        $expectedGlobal = hash('md5', "pfx_price_a\t2\tmd5-a\npfx_price_b\t3\tmd5-b\n");
        $this->assertSame($expectedGlobal, $result->globalMd5);
        $this->assertSame(5, $result->rows());
        [$indexerId, $label, $dumps, $globalMd5] = $this->stored[0];
        $this->assertSame(['catalog_product_price', 'core', $expectedGlobal], [$indexerId, $label, $globalMd5]);
        $this->assertSame(
            [
                ['pfx_price_a', 2, 'md5-a', 'indexer-bench/catalog_product_price/core/pfx_price_a.tsv'],
                ['pfx_price_b', 3, 'md5-b', 'indexer-bench/catalog_product_price/core/pfx_price_b.tsv'],
            ],
            array_map(static fn (TableDump $d): array => [$d->table, $d->rows, $d->md5, $d->filePath], $dumps)
        );
    }

    #[DataProvider('unsafeLabelProvider')]
    public function testLabelThatIsNotASafeDirectoryNameIsRejected(string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Label must be letters, digits, ".", "_" or "-"');
        try {
            $this->taker()->take('catalog_product_price', $label);
        } finally {
            $this->assertSame([], $this->fileCalls);
        }
    }

    /**
     * @return array<string,array{string}>
     */
    public static function unsafeLabelProvider(): array
    {
        return ['parent' => ['../etc'], 'slash' => ['a/b'], 'dot' => ['..'], 'space' => ['my label']];
    }

    public function testDumpsOfAPreviousSnapshotWithTheSameLabelAreRemoved(): void
    {
        $dir = '/m/var/indexer-bench/catalog_product_price/core';
        $this->taker([$dir . '/pfx_price_old.tsv', $dir . '/notes.txt'])->take('catalog_product_price', 'core');

        // Only dumps: a table of another dimensions mode must not survive in the label directory
        $this->assertSame(
            ['deleteFile:' . $dir . '/pfx_price_old.tsv', 'createDirectory:' . $dir],
            $this->fileCalls
        );
    }

    /**
     * Taker over fakes: two tables, files under /m/var
     *
     * @param string[] $existingFiles files already in the label directory
     * @return SnapshotTaker
     */
    private function taker(array $existingFiles = []): SnapshotTaker
    {
        $adapter = $this->createStub(IndexerAdapterInterface::class);
        $adapter->method('getIndexTables')->willReturn(['pfx_price_b', 'pfx_price_a']);
        $adapter->method('getCanonicalOrder')->willReturnMap([
            ['pfx_price_a', ['entity_id']],
            ['pfx_price_b', ['entity_id', 'website_id']],
        ]);
        $dumper = $this->createStub(CanonicalDumper::class);
        $dumper->method('dump')->willReturnCallback(
            function (string $table, array $key, ?string $file): TableDump {
                $this->dumped[] = [$table, $key, $file];
                $rows = ['pfx_price_a' => 2, 'pfx_price_b' => 3][$table];
                return new TableDump($table, $rows, 'md5-' . substr($table, -1), $file);
            }
        );
        $storage = $this->createStub(BenchSnapshot::class);
        $storage->method('replace')->willReturnCallback(
            function (string $indexerId, string $label, array $dumps, string $globalMd5): void {
                $this->stored[] = [$indexerId, $label, $dumps, $globalMd5];
            }
        );
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn('/m/var');
        $file = $this->createStub(File::class);
        $file->method('isExists')->willReturn($existingFiles !== []);
        $file->method('readDirectory')->willReturn($existingFiles);
        $file->method('createDirectory')->willReturnCallback(function (string $path): bool {
            $this->fileCalls[] = 'createDirectory:' . $path;
            return true;
        });
        $file->method('deleteFile')->willReturnCallback(function (string $path): bool {
            $this->fileCalls[] = 'deleteFile:' . $path;
            return true;
        });

        return new SnapshotTaker(
            new AdapterPool(['catalog_product_price' => $adapter]),
            $dumper,
            $storage,
            $directoryList,
            $file
        );
    }
}
