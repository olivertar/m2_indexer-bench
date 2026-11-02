<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\SnapshotCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SnapshotCommandTest extends TestCase
{
    private const LABELS = ['integration-a', 'integration-b', 'integration-c'];

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $file = $objectManager->get(File::class);
        $base = $objectManager->get(DirectoryList::class)->getPath(DirectoryList::VAR_DIR)
            . '/indexer-bench/catalog_product_price/';
        foreach (self::LABELS as $label) {
            if ($file->isExists($base . $label)) {
                $file->deleteDirectory($base . $label);
            }
        }
    }

    #[DbIsolation(true)]
    #[DataFixture(ProductFixture::class, ['price' => 10], 'product')]
    public function testSameIndexMatchesAndAChangedRowDiffers(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        [$first, $second, $third] = self::LABELS;
        $productId = (int)DataFixtureStorageManager::getStorage()->get('product')?->getData('entity_id');
        $objectManager->get(IndexerRegistry::class)->get('catalog_product_price')->reindexRow($productId);

        $this->assertSame(Command::SUCCESS, $this->snapshot(['-l' => $first])[0]);
        [$exitCode, $display] = $this->snapshot(['-l' => $second, '--diff' => $first]);
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('Output vs "' . $first . '": MATCH', $display);

        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $this->assertGreaterThan(0, $connection->update(
            $connection->getTableName('catalog_product_index_price'),
            ['final_price' => 9.5],
            ['entity_id = ?' => $productId]
        ));
        [$exitCode, $display] = $this->snapshot(['-l' => $third, '--diff' => $first]);

        $this->assertSame(Command::FAILURE, $exitCode, $display);
        $this->assertStringContainsString('Output vs "' . $first . '": DIFFER', $display);
        $this->assertMatchesRegularExpression('/catalog_product_index_price: (\d+) -> \1 rows/', $display);
        $this->assertStringContainsString('  ~ ' . $productId . "\t", $display);
    }

    /**
     * Run the command for catalog_product_price
     *
     * @param array<string,string> $options
     * @return array{int,string} exit code and display
     */
    private function snapshot(array $options): array
    {
        $tester = new CommandTester(Bootstrap::getObjectManager()->create(SnapshotCommand::class));
        $exitCode = $tester->execute(['-i' => 'catalog_product_price'] + $options);
        return [$exitCode, $tester->getDisplay()];
    }
}
