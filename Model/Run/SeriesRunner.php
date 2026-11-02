<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Run;

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
use Orangecat\IndexerBoost\Model\Monitor\Clock;

/**
 * Series of measured reindexes of one indexer, full or partial, each stored as a run
 */
class SeriesRunner
{
    /**
     * @param IndexerInterfaceFactory $indexerFactory
     * @param AdapterPool $adapterPool
     * @param MetricCollector $collector
     * @param EnvironmentFingerprint $fingerprint
     * @param HostInfo $hostInfo
     * @param BenchRun $storage
     * @param Clock $clock
     * @param RunProfiler $profiler
     * @param ProfileStore $profileStore
     * @param IndexerArea $indexerArea
     */
    public function __construct(
        private readonly IndexerInterfaceFactory $indexerFactory,
        private readonly AdapterPool $adapterPool,
        private readonly MetricCollector $collector,
        private readonly EnvironmentFingerprint $fingerprint,
        private readonly HostInfo $hostInfo,
        private readonly BenchRun $storage,
        private readonly Clock $clock,
        private readonly RunProfiler $profiler,
        private readonly ProfileStore $profileStore,
        private readonly IndexerArea $indexerArea
    ) {
    }

    /**
     * Measure the series and store every repeat
     *
     * @param RunRequest $request
     * @param callable(RunOutcome):void|null $onRepeat called after each repeat is stored
     * @return RunOutcome[]
     * @throws LocalizedException when the indexer is already working, or has no partial path for a partial run
     */
    public function run(RunRequest $request, ?callable $onRepeat = null): array
    {
        // Before any indexer is created, so it gets the adminhtml DI configuration
        $this->indexerArea->ensure();
        $adapter = $this->adapterPool->get($request->indexerId);
        $tables = $adapter->getIndexTables();
        $partialIndexerId = $request->ids === null ? null : $this->partialIndexerId($request, $adapter);
        $fingerprint = $this->fingerprint->collect();
        $parallel = ($this->hostInfo->getThreadsCount() ?? 1) > 1;

        $outcomes = [];
        for ($repeatIndex = 0; $repeatIndex < $request->repeat; $repeatIndex++) {
            $indexer = $this->load($request->indexerId);
            if ($indexer->isWorking()) {
                // Indexer::reindexAll() would return at once and the "measurement" would be empty
                throw new LocalizedException(__(
                    'Indexer %1 is "working": another reindex is running, or a dead one left it so '
                    . '(bin/magento indexer-boost:doctor --only=INDEXER_STUCK).',
                    $request->indexerId
                ));
            }
            if ($partialIndexerId === null && !$request->skipReset) {
                $indexer->invalidate();
            }
            $partialIndexer = $partialIndexerId === null ? null : $this->load($partialIndexerId);
            $profilerOn = $this->profiler->isProfilerOn();
            $attachProfiler = $request->profile && $this->profiler->isAvailable() && !$profilerOn;
            // A profiler already on (MAGE_PROFILER) slows the run down too, so it counts as profiled
            $profiled = $attachProfiler || $profilerOn;
            if ($attachProfiler) {
                $this->profiler->start();
            }
            $startedAt = $this->clock->now();
            $error = null;
            $this->collector->start($parallel);
            try {
                if ($partialIndexer !== null) {
                    $partialIndexer->reindexList($request->ids ?? []);
                } else {
                    $indexer->reindexAll();
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            // Always stopped, so the query counter does not keep counting; a failed run has no metrics
            $metrics = $this->collector->stop($tables);
            $reports = $attachProfiler ? $this->profiler->stop() : [];
            if ($error !== null) {
                $metrics = [];
            }
            $warmup = $request->warmup && $repeatIndex === 0;
            $runId = $this->storage->saveRun(
                new RunRecord(
                    $request->indexerId,
                    $request->label,
                    $request->mode,
                    $request->getKind(),
                    $repeatIndex,
                    $warmup,
                    $startedAt,
                    $this->clock->now(),
                    $error,
                    $fingerprint,
                    $request->notes,
                    $request->ids === null ? null : count($request->ids),
                    $request->seed,
                    $profiled
                ),
                $metrics
            );
            $outcome = new RunOutcome(
                $runId,
                $repeatIndex,
                $warmup,
                $metrics,
                $error,
                $this->profileStore->store($request->indexerId, $runId, $reports)
            );
            $outcomes[] = $outcome;
            if ($onRepeat !== null) {
                $onRepeat($outcome);
            }
            if ($error !== null) {
                // The index may be half built: later repeats would not measure the same work
                break;
            }
        }
        return $outcomes;
    }

    /**
     * Indexer whose reindexList() runs the partial path of the measured indexer
     *
     * @param RunRequest $request
     * @param IndexerAdapterInterface $adapter
     * @return string
     * @throws LocalizedException when the indexer has no partial path
     */
    private function partialIndexerId(RunRequest $request, IndexerAdapterInterface $adapter): string
    {
        return $adapter->getPartialIndexerId() ?? throw new LocalizedException(
            __('Indexer %1 has no partial reindex path to measure.', $request->indexerId)
        );
    }

    /**
     * Fresh copy of an indexer and its state
     *
     * @param string $indexerId
     * @return IndexerInterface
     */
    private function load(string $indexerId): IndexerInterface
    {
        return $this->indexerFactory->create()->load($indexerId);
    }
}
