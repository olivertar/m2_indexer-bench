<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Collector;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\Collector\QueryCounter;
use PHPUnit\Framework\TestCase;

/**
 * Metrics through the real adapter plugin and the real server counters (read-only statements on its own connection)
 */
#[DbIsolation(false)]
class MetricCollectorTest extends TestCase
{
    public function testStatementsOfTheAdapterAreCountedOnlyDuringTheMeasurement(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        // The test framework opens its shared connection before module plugins are configured: a connection created
        // now goes through the adapter interceptor, as every connection of a running application does
        $connection = $objectManager->get(ConnectionFactory::class)->create(
            (array)$objectManager->get(DeploymentConfig::class)->get('db/connection/default')
        );
        $counter = $objectManager->get(QueryCounter::class);
        $collector = $objectManager->create(MetricCollector::class);
        $table = $objectManager->get(ResourceConnection::class)->getTableName('store');
        $stores = (int)$connection->fetchOne("SELECT COUNT(*) FROM {$table}");

        $collector->start(false);
        $connection->fetchAll("SELECT * FROM {$table}");
        $connection->fetchCol("SELECT store_id FROM {$table}");
        $connection->query('SELECT SLEEP(0.01)');
        $metrics = $collector->stop([$table]);
        $connection->fetchAll("SELECT * FROM {$table}");

        $this->assertFalse($counter->isActive());
        $this->assertSame(3.0, $metrics[MetricCollector::SQL_QUERIES]);
        $this->assertGreaterThan(0.01, $metrics[MetricCollector::SQL_TIME]);
        $this->assertSame(0.0, $metrics[MetricCollector::SLOW_QUERIES]);
        $this->assertGreaterThanOrEqual(2 * $stores, $metrics['handler_read']);
        $this->assertSame((float)$stores, $metrics[MetricCollector::INDEX_ROWS]);
        $this->assertGreaterThan(0.0, $metrics[MetricCollector::PEAK_MEMORY]);
        foreach (array_keys(MetricCollector::STATUS_METRICS) as $code) {
            if (str_starts_with($code, 'rows_')) {
                // Innodb_rows_* exist on MySQL only
                continue;
            }
            $this->assertNotNull($metrics[$code], $code . ' should be read from SHOW GLOBAL STATUS');
        }
    }
}
