<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Profiler;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Console\Command\RunCommand;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends TestCase
{
    private const LABEL = 'integration-run-command';

    /**
     * @var string[] files and directories a profiled run left behind
     */
    private array $profileLeftovers = [];

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        // Metrics go with their runs (foreign key on delete cascade)
        $resource->getConnection()->delete(
            $resource->getTableName('orangecat_indexer_bench_run'),
            ['label = ?' => self::LABEL]
        );
        $file = Bootstrap::getObjectManager()->get(File::class);
        foreach ($this->profileLeftovers as $path) {
            if ($file->isDirectory($path)) {
                $file->deleteDirectory($path);
            } elseif ($file->isExists($path)) {
                $file->deleteFile($path);
            }
        }
    }

    // A full reindex swaps tables (DDL): no transaction to roll back
    #[DbIsolation(false)]
    public function testSeriesOfFullReindexesIsStoredWithMetricsAndFingerprint(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $tester = new CommandTester($objectManager->create(RunCommand::class));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '-l' => self::LABEL,
            '--repeat' => '2',
            '--warmup' => true,
            '--notes' => 'integration',
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('Median of 1 run:', $display);
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $runs = $connection->fetchAll(
            'SELECT run_id, repeat_index, warmup, status, kind, mode, fingerprint FROM '
            . $connection->getTableName('orangecat_indexer_bench_run') . ' WHERE label = ? ORDER BY repeat_index',
            [self::LABEL]
        );
        $this->assertSame(
            [['0', '1', 'ok', 'full', 'baseline'], ['1', '0', 'ok', 'full', 'baseline']],
            array_map(
                static fn (array $run): array => [(string)$run['repeat_index'], (string)$run['warmup'],
                    $run['status'], $run['kind'], $run['mode']],
                $runs
            )
        );
        $this->assertArrayHasKey('catalog', json_decode($runs[1]['fingerprint'], true));
        $metrics = $connection->fetchPairs(
            'SELECT metric_code, value FROM ' . $connection->getTableName('orangecat_indexer_bench_metric')
            . ' WHERE run_id = ?',
            [$runs[1]['run_id']]
        );
        $this->assertGreaterThan(0, (float)$metrics['wall_time_s']);
        $this->assertArrayHasKey('index_rows', $metrics);
        $this->assertArrayHasKey('handler_read', $metrics);
    }

    #[DbIsolation(false)]
    public function testProfiledRunKeepsTheMageOsReportAndIsStoredAsProfiled(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        if (!$objectManager->get(RunProfiler::class)->isAvailable()) {
            $this->markTestSkipped('mage-os/module-profiler is not installed and enabled');
        }
        $file = $objectManager->get(File::class);
        $profilerDir = $objectManager->get(DirectoryList::class)->getRoot() . '/var/log/profiler';
        $profilerDirExisted = $file->isDirectory($profilerDir);
        $tester = new CommandTester($objectManager->create(RunCommand::class));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => self::LABEL, '--profile' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $connection = $objectManager->get(ResourceConnection::class)->getConnection();
        $run = $connection->fetchRow(
            'SELECT run_id, profiled FROM ' . $connection->getTableName('orangecat_indexer_bench_run')
            . ' WHERE label = ?',
            [self::LABEL]
        );
        $this->assertSame('1', (string)$run['profiled']);
        $relativeDir = 'indexer-bench/catalog_product_price/' . $run['run_id'];
        $this->assertStringContainsString('profile in var/' . $relativeDir . '/', $display);
        $dir = $objectManager->get(DirectoryList::class)->getPath(DirectoryList::VAR_DIR) . '/' . $relativeDir;
        $reports = $file->readDirectory($dir);
        $this->profileLeftovers[] = $dir;
        foreach ($reports as $report) {
            $this->profileLeftovers[] = $profilerDir . '/' . basename($report);
        }
        if (!$profilerDirExisted) {
            $this->profileLeftovers[] = $profilerDir;
        }
        $this->assertCount(1, $reports);
        $report = json_decode($file->fileGetContents($reports[0]), true, 512, JSON_THROW_ON_ERROR);
        $timers = array_column($report['rows'], 'id');
        $this->assertContains('INDEXER:catalog_product_price::reindexAll', $timers);
        $this->assertNotEmpty(preg_grep('/->SQL:/', $timers));
        // The profiler is off again: a later run is not recorded
        $this->assertFalse(Profiler::isEnabled());
    }
}
