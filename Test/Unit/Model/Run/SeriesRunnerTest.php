<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Run;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\Fingerprint\EnvironmentFingerprint;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Profile\ProfileStore;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use Orangecat\IndexerBench\Model\ResourceModel\BenchRun;
use Orangecat\IndexerBench\Model\Run\IndexerArea;
use Orangecat\IndexerBench\Model\Run\SeriesRunner;
use Orangecat\IndexerBench\Model\Run\RunOutcome;
use Orangecat\IndexerBench\Model\Run\RunRecord;
use Orangecat\IndexerBench\Model\Run\RunRequest;
use Orangecat\IndexerBoost\Model\Monitor\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeriesRunnerTest extends TestCase
{
    /**
     * @var string[] calls on the indexer and the collector, in order
     */
    private array $calls = [];

    /**
     * @var string[] indexer IDs loaded, in order
     */
    private array $loaded = [];

    /**
     * @var RunRecord[] records stored, in order
     */
    private array $saved = [];

    /**
     * @var array<int,array<string,float|null>> metrics stored with each record
     */
    private array $savedMetrics = [];

    /**
     * @var array<int,array{string,int,string[]}> profile reports stored, in order
     */
    private array $storedProfiles = [];

    /**
     * @var int|null calls made before the indexer area was set, null when it was not set
     */
    private ?int $callsBeforeArea = null;

    public function testEveryRepeatIsResetMeasuredAndStoredWithTheFirstAsWarmUp(): void
    {
        $runner = $this->runner();

        $outcomes = $runner->run(
            new RunRequest('catalog_product_price', 'core', 'baseline', 3, true, false, 'notes')
        );

        $this->assertSame(
            array_merge(...array_fill(0, 3, ['invalidate', 'start:serial', 'reindexAll', 'stop:pfx_price_idx'])),
            $this->calls
        );
        $this->assertSame([0, 1, 2], array_map(static fn (RunRecord $r): int => $r->repeatIndex, $this->saved));
        $this->assertSame([true, false, false], array_map(static fn (RunRecord $r): bool => $r->warmup, $this->saved));
        $record = $this->saved[1];
        $this->assertSame(
            ['catalog_product_price', 'core', 'baseline', 'full', null, ['magento' => ['version' => '2.4.9']], 'notes'],
            [$record->indexerId, $record->label, $record->mode, $record->kind, $record->error, $record->fingerprint,
                $record->notes]
        );
        $this->assertSame(array_fill(0, 3, ['wall_time_s' => 1.5]), $this->savedMetrics);
        $this->assertSame(
            [[101, 0, true, null], [102, 1, false, null], [103, 2, false, null]],
            array_map(
                static fn (RunOutcome $o): array => [$o->runId, $o->repeatIndex, $o->warmup, $o->error],
                $outcomes
            )
        );
    }

    public function testSkipResetReindexesWithoutInvalidatingAndThreadsMeasureInParallelMode(): void
    {
        $this->runner(4)->run(new RunRequest('catalog_product_price', 'core', 'baseline', 1, false, true));

        $this->assertSame(['start:parallel', 'reindexAll', 'stop:pfx_price_idx'], $this->calls);
    }

    public function testFailedReindexIsStoredWithoutMetricsAndStopsTheSeries(): void
    {
        $outcomes = $this->runner(null, new \RuntimeException('Deadlock found'))
            ->run(new RunRequest('catalog_product_price', 'core', 'baseline', 3));

        $this->assertSame(['invalidate', 'start:serial', 'reindexAll', 'stop:pfx_price_idx'], $this->calls);
        $this->assertCount(1, $this->saved);
        $this->assertSame('Deadlock found', $this->saved[0]->error);
        $this->assertSame([[]], $this->savedMetrics);
        $this->assertSame([[101, 'Deadlock found', []]], array_map(
            static fn (RunOutcome $o): array => [$o->runId, $o->error, $o->metrics],
            $outcomes
        ));
    }

    public function testEachOutcomeIsReportedAsSoonAsItIsStored(): void
    {
        $reported = [];
        $this->runner()->run(
            new RunRequest('catalog_product_price', 'core', 'baseline', 2),
            function (RunOutcome $outcome) use (&$reported): void {
                $reported[] = [$outcome->runId, count($this->saved)];
            }
        );

        $this->assertSame([[101, 1], [102, 2]], $reported);
    }

    public function testPartialRunReindexesTheSampledIdsThroughThePartialIndexerWithoutReset(): void
    {
        $this->runner()->run(
            new RunRequest('catalog_product_price', 'core', repeat: 2, ids: [5, 9, 12], seed: 42)
        );

        $this->assertSame(
            array_merge(...array_fill(0, 2, ['start:serial', 'reindexList:5,9,12', 'stop:pfx_price_idx'])),
            $this->calls
        );
        $this->assertContains('catalog_product_price_partial', $this->loaded);
        $this->assertSame(
            [['partial', 3, 42], ['partial', 3, 42]],
            array_map(static fn (RunRecord $r): array => [$r->kind, $r->sampleSize, $r->seed], $this->saved)
        );
    }

    public function testPartialRunOfAnIndexerWithoutPartialPathIsRefused(): void
    {
        $runner = $this->runner(partialIndexerId: null);

        try {
            $runner->run(new RunRequest('catalog_product_price', 'core', ids: [5]));
            $this->fail('A partial run without partial path was measured');
        } catch (LocalizedException $e) {
            $this->assertSame(
                'Indexer catalog_product_price has no partial reindex path to measure.',
                $e->getMessage()
            );
        }
        $this->assertSame([], $this->calls);
    }

    public function testProfiledRunIsMeasuredUnderTheProfilerAndKeepsItsReports(): void
    {
        $outcomes = $this->runner()->run(new RunRequest('catalog_product_price', 'core', profile: true));

        $this->assertSame(
            ['invalidate', 'profile:start', 'start:serial', 'reindexAll', 'stop:pfx_price_idx', 'profile:stop'],
            $this->calls
        );
        $this->assertTrue($this->saved[0]->profiled);
        $this->assertSame([['catalog_product_price', 101, ['/var/log/profiler/a.json']]], $this->storedProfiles);
        $this->assertSame('indexer-bench/catalog_product_price/101', $outcomes[0]->profilePath);
    }

    /**
     * @param bool $profile --profile asked
     * @param bool $available mage-os/module-profiler enabled
     * @param bool $on profiler already on for the process (MAGE_PROFILER)
     * @param bool $profiled stored as profiled
     */
    #[DataProvider('profilerStateProvider')]
    public function testOnlyRunsSlowedDownByTheProfilerAreStoredAsProfiled(
        bool $profile,
        bool $available,
        bool $on,
        bool $profiled
    ): void {
        $outcomes = $this->runner(profilerAvailable: $available, profilerOn: $on)
            ->run(new RunRequest('catalog_product_price', 'core', profile: $profile));

        $this->assertSame(['invalidate', 'start:serial', 'reindexAll', 'stop:pfx_price_idx'], $this->calls);
        $this->assertSame($profiled, $this->saved[0]->profiled);
        $this->assertSame([], $this->storedProfiles);
        $this->assertNull($outcomes[0]->profilePath);
    }

    /**
     * @return array<string,array{bool,bool,bool,bool}>
     */
    public static function profilerStateProvider(): array
    {
        return [
            'not asked' => [false, true, false, false],
            'module missing' => [true, false, false, false],
            'already on' => [false, true, true, true],
            'asked while already on' => [true, true, true, true],
        ];
    }

    public function testIndexerAreaIsSetBeforeAnythingIsReindexed(): void
    {
        $this->runner()->run(new RunRequest('catalogrule_rule', 'core'));

        $this->assertSame(0, $this->callsBeforeArea);
    }

    public function testIndexerAlreadyWorkingIsNotMeasured(): void
    {
        // Indexer::reindexAll() returns without doing anything while the indexer is working
        $runner = $this->runner(null, null, true);

        try {
            $runner->run(new RunRequest('catalog_product_price', 'core'));
            $this->fail('A working indexer was measured');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('catalog_product_price is "working"', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->saved);
    }

    /**
     * Runner over fakes that record their calls in this test
     *
     * @param int|null $threads
     * @param \Throwable|null $reindexFailure
     * @param bool $working whether the indexer is already working
     * @param string|null $partialIndexerId partial path of the adapter
     * @param bool $profilerAvailable
     * @param bool $profilerOn
     * @return SeriesRunner
     */
    private function runner(
        ?int $threads = null,
        ?\Throwable $reindexFailure = null,
        bool $working = false,
        ?string $partialIndexerId = 'catalog_product_price_partial',
        bool $profilerAvailable = true,
        bool $profilerOn = false
    ): SeriesRunner {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('load')->willReturnCallback(function (string $id) use (&$indexer): IndexerInterface {
            $this->loaded[] = $id;
            return $indexer;
        });
        $indexer->method('isWorking')->willReturn($working);
        $indexer->method('reindexList')->willReturnCallback(function (array $ids): void {
            $this->calls[] = 'reindexList:' . implode(',', $ids);
        });
        $indexer->method('invalidate')->willReturnCallback(function (): void {
            $this->calls[] = 'invalidate';
        });
        $indexer->method('reindexAll')->willReturnCallback(function () use ($reindexFailure): void {
            $this->calls[] = 'reindexAll';
            if ($reindexFailure !== null) {
                throw $reindexFailure;
            }
        });
        $factory = $this->createStub(IndexerInterfaceFactory::class);
        $factory->method('create')->willReturn($indexer);

        $adapter = $this->createStub(IndexerAdapterInterface::class);
        $adapter->method('getIndexTables')->willReturn(['pfx_price_idx']);
        $adapter->method('getPartialIndexerId')->willReturn($partialIndexerId);
        $pool = new AdapterPool(['catalog_product_price' => $adapter, 'catalogrule_rule' => $adapter]);

        $collector = $this->createStub(MetricCollector::class);
        $collector->method('start')->willReturnCallback(function (bool $parallel): void {
            $this->calls[] = $parallel ? 'start:parallel' : 'start:serial';
        });
        $collector->method('stop')->willReturnCallback(function (array $tables): array {
            $this->calls[] = 'stop:' . implode(',', $tables);
            return ['wall_time_s' => 1.5];
        });

        $fingerprint = $this->createStub(EnvironmentFingerprint::class);
        $fingerprint->method('collect')->willReturn(['magento' => ['version' => '2.4.9']]);
        $hostInfo = $this->createStub(HostInfo::class);
        $hostInfo->method('getThreadsCount')->willReturn($threads);

        $storage = $this->createStub(BenchRun::class);
        $storage->method('saveRun')->willReturnCallback(function (RunRecord $record, array $metrics): int {
            $this->saved[] = $record;
            $this->savedMetrics[] = $metrics;
            return 100 + count($this->saved);
        });

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-31 11:00:00'));

        $profiler = $this->createStub(RunProfiler::class);
        $profiler->method('isAvailable')->willReturn($profilerAvailable);
        $profiler->method('isProfilerOn')->willReturn($profilerOn);
        $profiler->method('start')->willReturnCallback(function (): void {
            $this->calls[] = 'profile:start';
        });
        $profiler->method('stop')->willReturnCallback(function (): array {
            $this->calls[] = 'profile:stop';
            return ['/var/log/profiler/a.json'];
        });
        $area = $this->createStub(IndexerArea::class);
        $area->method('ensure')->willReturnCallback(function (): void {
            $this->callsBeforeArea = count($this->calls);
        });
        $profileStore = $this->createStub(ProfileStore::class);
        $profileStore->method('store')->willReturnCallback(
            function (string $indexerId, int $runId, array $reports): ?string {
                if (!$reports) {
                    return null;
                }
                $this->storedProfiles[] = [$indexerId, $runId, $reports];
                return 'indexer-bench/' . $indexerId . '/' . $runId;
            }
        );

        return new SeriesRunner(
            $factory,
            $pool,
            $collector,
            $fingerprint,
            $hostInfo,
            $storage,
            $clock,
            $profiler,
            $profileStore,
            $area
        );
    }
}
