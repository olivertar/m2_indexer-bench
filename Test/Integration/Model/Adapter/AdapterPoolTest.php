<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Adapter;

use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\DimensionModeConfiguration;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Adapter\PriceAdapter;
use Orangecat\IndexerBench\Model\Snapshot\CanonicalDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every adapter matches the installed indexer and schema: its tables exist and their canonical key is dumpable
 */
class AdapterPoolTest extends TestCase
{
    /**
     * @return array<string,array{string}>
     */
    public static function indexerProvider(): array
    {
        $ids = [
            'catalog_category_product', 'catalog_product_price', 'catalogrule_rule', 'catalogrule_product',
            'catalog_product_attribute', 'cataloginventory_stock',
        ];
        return array_combine($ids, array_map(static fn (string $id): array => [$id], $ids));
    }

    #[DataProvider('indexerProvider')]
    public function testAdapterTablesExistAndAreDumpableWithTheirCanonicalKey(string $indexerId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $adapter = $objectManager->create(AdapterPool::class)->get($indexerId);
        $dumper = $objectManager->create(CanonicalDumper::class);

        $this->assertArrayHasKey($indexerId, $objectManager->get(ConfigInterface::class)->getIndexers());
        $partial = $adapter->getPartialIndexerId();
        $this->assertArrayHasKey((string)$partial, $objectManager->get(ConfigInterface::class)->getIndexers());
        $this->assertNotEmpty($adapter->getIndexTables());
        foreach ($adapter->getIndexTables() as $table) {
            // Throws when the table is missing or the key does not identify its rows
            $dump = $dumper->dump($table, $adapter->getCanonicalOrder($table));
            $this->assertSame(32, strlen($dump->md5), $table);
        }
    }

    public function testCatalogCategoryProductDumpsTheDefaultStoreViewTable(): void
    {
        $adapter = Bootstrap::getObjectManager()->create(AdapterPool::class)->get('catalog_category_product');

        $this->assertContains(
            Bootstrap::getObjectManager()->get(ResourceConnection::class)
                ->getTableName('catalog_category_product_index_store1'),
            $adapter->getIndexTables()
        );
    }

    #[Config('indexer/catalog_product_price/dimensions_mode', 'website')]
    public function testPriceTablesFollowTheDimensionsMode(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        // DimensionModeConfiguration caches the mode: fresh instances read the fixture value
        $factory = $objectManager->create(
            DimensionCollectionFactory::class,
            ['dimensionModeConfiguration' => $objectManager->create(DimensionModeConfiguration::class)]
        );
        $adapter = $objectManager->create(PriceAdapter::class, ['dimensionCollectionFactory' => $factory]);

        $this->assertSame(
            $objectManager->get(ResourceConnection::class)->getTableName('catalog_product_index_price_ws1'),
            $adapter->getIndexTables()[0]
        );
    }

    public function testInventoryWithOnlyTheDefaultStockHasNothingToDump(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();

        $this->assertSame(
            'VIEW',
            $connection->fetchOne(
                'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$connection->getTableName('inventory_stock_1')]
            )
        );
        $this->assertSame([], $objectManager->create(AdapterPool::class)->get('inventory')->getIndexTables());
    }
}
