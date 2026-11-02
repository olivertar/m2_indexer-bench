<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\RunPartialCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunPartialCommandTest extends TestCase
{
    #[DbIsolation(true)]
    #[DataFixture(ProductFixture::class, ['price' => 15.5], 'product')]
    public function testPartialReindexOfGivenIdsIsStoredAsPartialRun(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $productId = (int)DataFixtureStorageManager::getStorage()->get('product')?->getData('entity_id');
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        // Saving the fixture may index it already: only reindexList() can bring this row back
        $connection->delete(
            $connection->getTableName('catalog_product_index_price'),
            ['entity_id = ?' => $productId]
        );
        $tester = new CommandTester($objectManager->create(RunPartialCommand::class));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '-l' => 'integration-run-partial',
            '--ids' => (string)$productId,
            '--repeat' => '2',
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('1 IDs given', $display);
        $this->assertSame(
            [['partial', '1', null, 'ok'], ['partial', '1', null, 'ok']],
            array_map(
                static fn (array $run): array => [$run['kind'], (string)$run['sample_size'], $run['seed'],
                    $run['status']],
                $connection->fetchAll(
                    'SELECT kind, sample_size, seed, status FROM '
                    . $connection->getTableName('orangecat_indexer_bench_run')
                    . ' WHERE label = ? ORDER BY repeat_index',
                    ['integration-run-partial']
                )
            )
        );
        $this->assertNotFalse($connection->fetchOne(
            'SELECT 1 FROM ' . $connection->getTableName('catalog_product_index_price') . ' WHERE entity_id = ?',
            [$productId]
        ));
    }
}
