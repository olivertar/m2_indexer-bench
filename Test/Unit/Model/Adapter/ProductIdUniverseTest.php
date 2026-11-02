<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Adapter;

use Orangecat\IndexerBench\Model\Adapter\ProductIdUniverse;
use Orangecat\IndexerBoost\Model\Doctor\Db\ReadOnlyConnection;
use PHPUnit\Framework\TestCase;

class ProductIdUniverseTest extends TestCase
{
    public function testEachProductCountsOnceEvenWithOneRowPerStagingVersion(): void
    {
        // Adobe Commerce staging keeps one catalog_product_entity row per version (row_id) of an entity_id
        $connection = $this->createStub(ReadOnlyConnection::class);
        $connection->method('getTableName')->willReturnArgument(0);
        $connection->method('fetchCol')->willReturn(['1', '1', '2', '5', '5']);

        $this->assertSame([1, 2, 5], (new ProductIdUniverse($connection))->getIds());
    }
}
