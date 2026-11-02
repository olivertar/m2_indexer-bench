<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Profile;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Model\Profile\ProfileStore;
use PHPUnit\Framework\TestCase;

class ProfileStoreTest extends TestCase
{
    public function testReportsAreCopiedUnderTheRunId(): void
    {
        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('createDirectory')->with('/m/var/indexer-bench/catalog_product_price/19');
        $copies = [];
        $file->method('copy')->willReturnCallback(function (string $source, string $destination) use (&$copies): bool {
            $copies[] = [$source, $destination];
            return true;
        });

        $dir = $this->store($file)->store('catalog_product_price', 19, ['/m/var/log/profiler/a.json', '/m/b/c.json']);

        $this->assertSame('indexer-bench/catalog_product_price/19', $dir);
        $this->assertSame([
            ['/m/var/log/profiler/a.json', '/m/var/indexer-bench/catalog_product_price/19/a.json'],
            ['/m/b/c.json', '/m/var/indexer-bench/catalog_product_price/19/c.json'],
        ], $copies);
    }

    public function testNoReportLeavesNoDirectory(): void
    {
        $file = $this->createMock(File::class);
        $file->expects($this->never())->method('createDirectory');

        $this->assertNull($this->store($file)->store('catalog_product_price', 19, []));
    }

    /**
     * Store over a fake var/ at /m/var
     *
     * @param File $file
     * @return ProfileStore
     */
    private function store(File $file): ProfileStore
    {
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturn('/m/var');
        return new ProfileStore($directoryList, $file);
    }
}
