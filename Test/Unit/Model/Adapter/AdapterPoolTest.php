<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use PHPUnit\Framework\TestCase;

class AdapterPoolTest extends TestCase
{
    public function testAdapterIsFoundByIndexerId(): void
    {
        $price = $this->createStub(IndexerAdapterInterface::class);
        $pool = new AdapterPool(['catalog_product_price' => $price]);

        $this->assertSame($price, $pool->get('catalog_product_price'));
        $this->assertSame(['catalog_product_price'], $pool->getIndexerIds());
    }

    public function testUnknownIndexerListsTheSupportedOnes(): void
    {
        $pool = new AdapterPool([
            'catalog_product_price' => $this->createStub(IndexerAdapterInterface::class),
            'inventory' => $this->createStub(IndexerAdapterInterface::class),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'No benchmark adapter for "customer_grid". Supported: catalog_product_price, inventory'
        );

        $pool->get('customer_grid');
    }
}
