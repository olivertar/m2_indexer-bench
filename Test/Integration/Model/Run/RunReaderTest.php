<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Integration\Model\Run;

use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\TestCase;

class RunReaderTest extends TestCase
{
    #[DbIsolation(true)]
    public function testComparableRunsLeaveOutWarmUpFailedAndProfiledRuns(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(BenchRun::class);
        $save = static fn (int $repeat, bool $warmup, ?string $error, bool $profiled = false, string $label = 'core'):
            int => $storage->saveRun(
                new RunRecord(
                    'catalog_product_price',
                    $label,
                    'baseline',
                    'full',
                    $repeat,
                    $warmup,
                    new \DateTimeImmutable('2026-10-31 10:00:0' . $repeat),
                    new \DateTimeImmutable('2026-10-31 10:01:0' . $repeat),
                    $error,
                    ['catalog' => ['products' => 2040]],
                    'n',
                    profiled: $profiled
                ),
                $error === null ? ['wall_time_s' => 1.0 + $repeat, 'sql_queries' => null] : []
            );
        $save(0, true, null);
        $second = $save(1, false, null);
        $save(2, false, 'Deadlock');
        $save(3, false, null, true);
        $fifth = $save(4, false, null);
        $save(5, false, null, false, 'other');
        $reader = $objectManager->create(RunReader::class);

        $runs = $reader->getComparableRuns('catalog_product_price', 'core');

        $this->assertSame([$second, $fifth], array_map(static fn (StoredRun $run): int => $run->runId, $runs));
        $this->assertSame(['sql_queries' => null, 'wall_time_s' => 2.0], $runs[0]->metrics);
        $this->assertSame(['catalog' => ['products' => 2040]], $runs[0]->fingerprint);
        $this->assertSame(['core', 'baseline', 'full', 'ok', false, false], [
            $runs[0]->label, $runs[0]->mode, $runs[0]->kind, $runs[0]->status, $runs[0]->warmup, $runs[0]->profiled,
        ]);
        $this->assertSame($fifth, $reader->getRun($fifth)?->runId);
        $this->assertNull($reader->getRun($fifth + 1000));
    }

    #[DbIsolation(true)]
    public function testRecentRunsAreTheLastOnesOfTheIndexerInRunOrder(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(BenchRun::class);
        $save = static fn (string $indexerId, ?string $error = null): int => $storage->saveRun(
            new RunRecord(
                $indexerId,
                'core',
                'baseline',
                'full',
                0,
                false,
                new \DateTimeImmutable('2026-10-31 10:00:00'),
                new \DateTimeImmutable('2026-10-31 10:01:00'),
                $error,
                [],
                null
            ),
            ['wall_time_s' => 1.0]
        );
        $save('catalog_product_price');
        $second = $save('catalog_product_price', 'Deadlock');
        $third = $save('catalog_product_price');
        $save('catalog_category_product');
        $fifth = $save('catalog_product_price');

        $runs = $objectManager->create(RunReader::class)->getRecentRuns('catalog_product_price', 3);

        $this->assertSame([$second, $third, $fifth], array_map(static fn (StoredRun $run): int => $run->runId, $runs));
        $this->assertSame('failed', $runs[0]->status);
        $this->assertSame('Deadlock', $runs[0]->error);
        $this->assertNull($runs[1]->error);
        $this->assertSame($fifth, $objectManager->create(RunReader::class)->getLatestRun()?->runId);
    }

    #[DbIsolation(true)]
    public function testIndexersWithRunsAndTheLabelsOfEachInFirstRunOrder(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storage = $objectManager->create(BenchRun::class);
        $save = static fn (string $indexerId, string $label, string $startedAt): int => $storage->saveRun(
            new RunRecord(
                $indexerId,
                $label,
                'baseline',
                'full',
                0,
                false,
                new \DateTimeImmutable($startedAt),
                new \DateTimeImmutable($startedAt),
                null,
                [],
                null
            ),
            []
        );
        $save('zz_price', 'core', '2026-10-31 10:00:00');
        $save('zz_price', 'boost', '2026-10-31 11:00:00');
        $save('zz_price', 'core', '2026-10-31 12:00:00');
        $save('zz_category', 'core', '2026-10-30 09:00:00');
        $reader = $objectManager->create(RunReader::class);

        $indexers = array_intersect_key($reader->getIndexers(), ['zz_price' => 1, 'zz_category' => 1]);

        $this->assertSame(
            [
                'zz_category' => ['runs' => 1, 'last_started_at' => '2026-10-30 09:00:00'],
                'zz_price' => ['runs' => 3, 'last_started_at' => '2026-10-31 12:00:00'],
            ],
            $indexers
        );
        $this->assertSame(['core', 'boost'], $reader->getLabels('zz_price'));
        $this->assertSame([], $reader->getLabels('zz_unknown'));
    }
}
