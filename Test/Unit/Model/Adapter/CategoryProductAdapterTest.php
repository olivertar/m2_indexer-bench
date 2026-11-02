<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Orangecat\IndexerBench\Model\Adapter\CategoryProductAdapter;
use Orangecat\IndexerBench\Model\Adapter\ProductIdUniverse;
use PHPUnit\Framework\TestCase;

class CategoryProductAdapterTest extends TestCase
{
    public function testOneLiveTablePerStoreViewResolvedByTheCore(): void
    {
        $stores = [];
        foreach ([1, 3] as $storeId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())->method('getStores')->with(false)->willReturn($stores);
        $tableMaintainer = $this->createStub(TableMaintainer::class);
        $tableMaintainer->method('getMainTable')->willReturnCallback(
            static fn (int $storeId): string => 'pfx_catalog_category_product_index_store' . $storeId
        );

        $adapter = new CategoryProductAdapter(
            $storeManager,
            $tableMaintainer,
            $this->createStub(ProductIdUniverse::class)
        );

        $this->assertSame('catalog_category_product', $adapter->getIndexerId());
        $this->assertSame(
            ['pfx_catalog_category_product_index_store1', 'pfx_catalog_category_product_index_store3'],
            $adapter->getIndexTables()
        );
        $this->assertSame(
            ['category_id', 'product_id', 'store_id'],
            $adapter->getCanonicalOrder('pfx_catalog_category_product_index_store3')
        );
        $this->assertSame('catalog_product_category', $adapter->getPartialIndexerId());
    }
}
