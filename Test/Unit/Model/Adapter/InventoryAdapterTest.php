<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Orangecat\IndexerBench\Model\Adapter\InventoryAdapter;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class InventoryAdapterTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testOnlyStockIndexTablesAreDumpedNotTheDefaultStockView(): void
    {
        $binds = [];
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            function (string $sql, string $method, array $bind) use (&$binds): array {
                $binds[] = [$method, $bind];
                $this->assertStringContainsString("TABLE_TYPE = 'BASE TABLE'", $sql);
                // LIKE also matches inventory_stock_sales_channel; the view inventory_stock_1 is not a base table
                return ['pfx_inventory_stock_10', 'pfx_inventory_stock_2', 'pfx_inventory_stock_sales_channel'];
            }
        )));

        $adapter = new InventoryAdapter($connection);

        $this->assertSame('inventory', $adapter->getIndexerId());
        // Sorted by stock ID
        $this->assertSame(['pfx_inventory_stock_2', 'pfx_inventory_stock_10'], $adapter->getIndexTables());
        $this->assertSame([['fetchCol', ['pfx\_inventory\_stock\_%']]], $binds);
        $this->assertSame(['sku'], $adapter->getCanonicalOrder('pfx_inventory_stock_2'));
        $this->assertNull($adapter->getPartialIndexerId());
        $this->assertSame([], $adapter->getEntityIdUniverse());
    }
}
