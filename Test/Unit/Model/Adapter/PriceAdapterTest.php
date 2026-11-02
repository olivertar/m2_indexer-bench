<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\TableMaintainer;
use Magento\Framework\Indexer\Dimension;
use Magento\Framework\Indexer\MultiDimensionProvider;
use Orangecat\IndexerBench\Model\Adapter\PriceAdapter;
use Orangecat\IndexerBench\Model\Adapter\ProductIdUniverse;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class PriceAdapterTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testOneTablePerDimensionOfTheActiveModePlusTierAndWebsiteTables(): void
    {
        $adapter = $this->adapter([
            [new Dimension('ws', '1'), new Dimension('cg', '0')],
            [new Dimension('ws', '1'), new Dimension('cg', '1')],
        ]);

        $this->assertSame(
            [
                'pfx_catalog_product_index_price_ws1_cg0',
                'pfx_catalog_product_index_price_ws1_cg1',
                'pfx_catalog_product_index_tier_price',
                'pfx_catalog_product_index_website',
            ],
            $adapter->getIndexTables()
        );
        $this->assertSame(
            ['entity_id', 'customer_group_id', 'website_id'],
            $adapter->getCanonicalOrder('pfx_catalog_product_index_price_ws1_cg1')
        );
        $this->assertSame(['website_id'], $adapter->getCanonicalOrder('pfx_catalog_product_index_website'));
        $this->assertSame('catalog_product_price', $adapter->getPartialIndexerId());
    }

    public function testWithoutDimensionsTheMainTableIsTheBaseOne(): void
    {
        $this->assertSame('pfx_catalog_product_index_price', $this->adapter([[]])->getIndexTables()[0]);
    }

    /**
     * @param array<int,Dimension[]> $dimensions
     * @return PriceAdapter
     */
    private function adapter(array $dimensions): PriceAdapter
    {
        $provider = $this->createStub(MultiDimensionProvider::class);
        $provider->method('getIterator')->willReturn(new \ArrayIterator($dimensions));
        $factory = $this->createStub(DimensionCollectionFactory::class);
        $factory->method('create')->willReturn($provider);
        $tableMaintainer = $this->createStub(TableMaintainer::class);
        $tableMaintainer->method('getMainTableByDimensions')->willReturnCallback(
            static fn (array $dims): string => 'pfx_catalog_product_index_price' . implode('', array_map(
                static fn (Dimension $dimension): string => '_' . $dimension->getName() . $dimension->getValue(),
                $dims
            ))
        );
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            static fn (string $sql): never => throw new \LogicException($sql)
        )));

        return new PriceAdapter($factory, $tableMaintainer, $connection, $this->createStub(ProductIdUniverse::class));
    }
}
