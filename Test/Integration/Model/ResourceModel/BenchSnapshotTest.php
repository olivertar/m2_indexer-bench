<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\ResourceModel;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\ResourceModel\BenchSnapshot;
use Orangecat\IndexerBench\Model\Snapshot\TableDump;
use PHPUnit\Framework\TestCase;

class BenchSnapshotTest extends TestCase
{
    #[DbIsolation(true)]
    public function testSnapshotOfALabelReplacesThePreviousOne(): void
    {
        $storage = Bootstrap::getObjectManager()->create(BenchSnapshot::class);
        $storage->replace('catalog_product_price', 'core', [
            new TableDump('catalog_product_index_price', 10, str_repeat('a', 32), 'indexer-bench/x/core/old.tsv'),
            new TableDump('catalog_product_index_tier_price', 1, str_repeat('b', 32)),
        ], str_repeat('c', 32));
        $storage->replace('catalog_product_price', 'core', [
            new TableDump('catalog_product_index_price', 12, str_repeat('d', 32), 'indexer-bench/x/core/new.tsv'),
        ], str_repeat('e', 32));
        $storage->replace('catalog_product_price', 'other', [
            new TableDump('catalog_product_index_price', 3, str_repeat('f', 32)),
        ], str_repeat('f', 32));

        $this->assertSame(
            [
                '*' => ['rows' => 12, 'md5' => str_repeat('e', 32), 'file_path' => null],
                'catalog_product_index_price' => [
                    'rows' => 12,
                    'md5' => str_repeat('d', 32),
                    'file_path' => 'indexer-bench/x/core/new.tsv',
                ],
            ],
            $storage->loadLabel('catalog_product_price', 'core')
        );
        $this->assertSame([], $storage->loadLabel('catalog_product_price', 'missing'));
    }
}
