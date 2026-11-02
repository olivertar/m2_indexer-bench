<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use Orangecat\IndexerBench\Model\Run\IdSampler;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunRequest;
use Orangecat\IndexerBench\Model\Run\SeriesRunner;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measure partial reindexes (reindexList) of the same reproducible sample of entity IDs
 */
class RunPartialCommand extends AbstractSeriesCommand
{
    private const NAME = 'indexer-bench:run-partial';
    private const OPTION_COUNT = 'count';
    private const OPTION_SEED = 'seed';
    private const OPTION_IDS = 'ids';
    private const DEFAULT_SEED = 42;
    private const MAX_UNKNOWN_SHOWN = 10;

    /**
     * @param Safeguards $safeguards
     * @param SeriesRunner $runner
     * @param HostInfo $hostInfo
     * @param AdapterPool $adapterPool
     * @param Median $median
     * @param RunProfiler $profiler
     * @param IdSampler $sampler
     */
    public function __construct(
        Safeguards $safeguards,
        SeriesRunner $runner,
        HostInfo $hostInfo,
        AdapterPool $adapterPool,
        Median $median,
        RunProfiler $profiler,
        private readonly IdSampler $sampler
    ) {
        parent::__construct($safeguards, $runner, $hostInfo, $adapterPool, $median, $profiler);
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Measure partial reindexes of a fixed sample of entity IDs (development and staging only)')
            ->addSeriesOptions()
            ->addOption(self::OPTION_COUNT, 'c', InputOption::VALUE_REQUIRED, 'Number of entity IDs to sample')
            ->addOption(
                self::OPTION_SEED,
                null,
                InputOption::VALUE_REQUIRED,
                'Sampling seed; the same seed gives the same IDs',
                (string)self::DEFAULT_SEED
            )
            ->addOption(
                self::OPTION_IDS,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated entity IDs to reindex instead of a sample'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function buildRequest(InputInterface $input, OutputInterface $output): RunRequest
    {
        $indexerId = (string)$input->getOption(self::OPTION_INDEXER);
        $adapter = $this->adapterPool->get($indexerId);
        if ($adapter->getPartialIndexerId() === null) {
            throw new \InvalidArgumentException(
                sprintf('Indexer %s has no partial reindex path to measure.', $indexerId)
            );
        }
        $universe = $adapter->getEntityIdUniverse();
        $count = $input->getOption(self::OPTION_COUNT);
        $idList = $input->getOption(self::OPTION_IDS);
        if (($count === null) === ($idList === null)) {
            throw new \InvalidArgumentException(
                $count === null ? 'Give -c/--count or --ids' : 'Give -c/--count or --ids, not both'
            );
        }

        if ($idList !== null) {
            $seed = null;
            $ids = $this->parseIds((string)$idList, $universe);
            $output->writeln(sprintf('%d IDs given', count($ids)));
        } else {
            if ((int)$count < 1) {
                throw new \InvalidArgumentException('--count must be at least 1');
            }
            $seed = (int)$input->getOption(self::OPTION_SEED);
            $ids = $this->sampler->sample($universe, (int)$count, $seed);
            $output->writeln(sprintf('%d IDs sampled with seed %d', count($ids), $seed));
        }

        return new RunRequest(
            $indexerId,
            (string)$input->getOption(self::OPTION_LABEL),
            (string)$input->getOption(self::OPTION_MODE),
            (int)$input->getOption(self::OPTION_REPEAT),
            false,
            false,
            $this->notes($input),
            $ids,
            $seed,
            $this->profile($input)
        );
    }

    /**
     * Distinct sorted IDs of a comma-separated list, all of them in the universe of the adapter
     *
     * @param string $list
     * @param int[] $universe
     * @return int[]
     * @throws \InvalidArgumentException
     */
    private function parseIds(string $list, array $universe): array
    {
        $ids = [];
        foreach (explode(',', $list) as $value) {
            $value = trim($value);
            if (!ctype_digit($value)) {
                throw new \InvalidArgumentException(sprintf('Not an entity ID: "%s"', $value));
            }
            $ids[(int)$value] = (int)$value;
        }
        ksort($ids);
        $unknown = array_diff($ids, $universe);
        if ($unknown) {
            // Unknown IDs would make the reindex do less work than the operator thinks
            throw new \InvalidArgumentException(
                'Unknown entity IDs: ' . implode(', ', array_slice($unknown, 0, self::MAX_UNKNOWN_SHOWN))
            );
        }
        return array_values($ids);
    }
}
