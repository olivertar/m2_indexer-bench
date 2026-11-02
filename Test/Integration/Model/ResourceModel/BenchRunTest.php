<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use PHPUnit\Framework\TestCase;

class BenchRunTest extends TestCase
{
    #[DbIsolation(true)]
    public function testRunIsStoredWithItsMetricsAndNotAvailableValues(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $record = new RunRecord(
            indexerId: 'catalog_product_price',
            label: 'core',
            mode: 'baseline',
            kind: 'full',
            repeatIndex: 1,
            warmup: false,
            startedAt: new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('Europe/Madrid')),
            finishedAt: new \DateTimeImmutable('2026-10-31 11:00:02', new \DateTimeZone('UTC')),
            error: null,
            fingerprint: ['magento' => ['version' => '2.4.9']],
            notes: 'first try'
        );

        $runId = $objectManager->create(BenchRun::class)->saveRun(
            $record,
            ['wall_time_s' => 2.123456, 'sql_queries' => null]
        );

        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $run = $connection->fetchRow(
            'SELECT * FROM ' . $connection->getTableName('orangecat_indexer_bench_run') . ' WHERE run_id = ?',
            [$runId]
        );
        $this->assertSame('catalog_product_price', $run['indexer_id']);
        $this->assertSame('full', $run['kind']);
        $this->assertSame('1', (string)$run['repeat_index']);
        $this->assertSame('0', (string)$run['warmup']);
        $this->assertSame('2026-10-31 11:00:00', $run['started_at']);
        $this->assertSame('2026-10-31 11:00:02', $run['finished_at']);
        $this->assertSame('ok', $run['status']);
        $this->assertNull($run['error']);
        $this->assertSame(['magento' => ['version' => '2.4.9']], json_decode($run['fingerprint'], true));
        $this->assertSame('first try', $run['notes']);
        $this->assertSame(
            ['sql_queries' => null, 'wall_time_s' => '2.123456'],
            $connection->fetchPairs(
                'SELECT metric_code, value FROM ' . $connection->getTableName('orangecat_indexer_bench_metric')
                . ' WHERE run_id = ? ORDER BY metric_code',
                [$runId]
            )
        );
    }
}
