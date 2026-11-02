<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Orangecat\IndexerBench\Model\Adapter\ProductIdUniverse;
use Orangecat\IndexerBench\Model\Adapter\TableAdapter;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use Orangecat\IndexerBoost\Test\Unit\Fake\WriteGuardAdapterTrait;
use PHPUnit\Framework\TestCase;

class TableAdapterTest extends TestCase
{
    use WriteGuardAdapterTrait;

    public function testTablesAreResolvedWithThePrefixAndKeepTheirCanonicalOrder(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('catalogrule_rule', $adapter->getIndexerId());
        $this->assertSame(['pfx_catalogrule_product', 'pfx_catalogrule_group_website'], $adapter->getIndexTables());
        $this->assertSame(
            ['rule_id', 'customer_group_id', 'website_id'],
            $adapter->getCanonicalOrder('pfx_catalogrule_group_website')
        );
        $this->assertSame('catalogrule_product', $adapter->getPartialIndexerId());
    }

    public function testUnknownTableHasNoCanonicalOrder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pfx_sales_order is not an index table of catalogrule_rule');

        $this->adapter()->getCanonicalOrder('pfx_sales_order');
    }

    public function testEntityUniverseIsEveryProductIdInOrder(): void
    {
        $this->assertSame([1, 2, 10], $this->adapter()->getEntityIdUniverse());
    }

    /**
     * @return TableAdapter
     */
    private function adapter(): TableAdapter
    {
        $connection = new ReadOnlyConnection($this->createResourceConnection($this->createWriteGuardAdapter(
            static fn (string $sql): array =>
                $sql === 'SELECT DISTINCT entity_id FROM pfx_catalog_product_entity ORDER BY entity_id'
                    ? ['1', '2', '10']
                    : throw new \LogicException($sql)
        )));

        return new TableAdapter(
            $connection,
            new ProductIdUniverse($connection),
            'catalogrule_rule',
            [
                'catalogrule_product' => ['rule_id', 'from_time', 'to_time', 'website_id', 'customer_group_id',
                    'product_id', 'sort_order'],
                'catalogrule_group_website' => ['rule_id', 'customer_group_id', 'website_id'],
            ],
            'catalogrule_product'
        );
    }
}
