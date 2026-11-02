<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunOutcome;
use Orangecat\IndexerBench\Model\Run\RunRequest;
use Orangecat\IndexerBench\Model\Run\SeriesRunner;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared flow of the commands measuring a series of reindexes: options, safeguards, progress and median
 */
abstract class AbstractSeriesCommand extends Command
{
    protected const OPTION_INDEXER = 'indexer';
    protected const OPTION_LABEL = 'label';
    protected const OPTION_MODE = 'mode';
    protected const OPTION_REPEAT = 'repeat';
    protected const OPTION_THREADS = 'threads';
    protected const OPTION_NOTES = 'notes';
    protected const OPTION_STRICT = 'strict';
    protected const OPTION_PROFILE = 'profile';

    /**
     * @param Safeguards $safeguards
     * @param SeriesRunner $runner
     * @param HostInfo $hostInfo
     * @param AdapterPool $adapterPool
     * @param Median $median
     * @param RunProfiler $profiler
     */
    public function __construct(
        private readonly Safeguards $safeguards,
        private readonly SeriesRunner $runner,
        private readonly HostInfo $hostInfo,
        protected readonly AdapterPool $adapterPool,
        private readonly Median $median,
        private readonly RunProfiler $profiler
    ) {
        parent::__construct();
    }

    /**
     * Request of the series from the options
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return RunRequest
     * @throws \InvalidArgumentException
     */
    abstract protected function buildRequest(InputInterface $input, OutputInterface $output): RunRequest;

    /**
     * Add the options every series command has
     *
     * @return $this
     */
    protected function addSeriesOptions(): static
    {
        return $this->addOption(self::OPTION_INDEXER, 'i', InputOption::VALUE_REQUIRED, 'Indexer ID')
            ->addOption(self::OPTION_LABEL, 'l', InputOption::VALUE_REQUIRED, 'Label of the series, e.g. core')
            ->addOption(
                self::OPTION_MODE,
                null,
                InputOption::VALUE_REQUIRED,
                'baseline|candidate',
                RunRequest::MODE_BASELINE
            )
            ->addOption(self::OPTION_REPEAT, null, InputOption::VALUE_REQUIRED, 'Number of measurements', '1')
            ->addOption(
                self::OPTION_THREADS,
                null,
                InputOption::VALUE_REQUIRED,
                'Expected MAGE_INDEXER_THREADS_COUNT; the command refuses to run with another value'
            )
            ->addOption(self::OPTION_NOTES, null, InputOption::VALUE_REQUIRED, 'Free notes stored with the runs')
            ->addOption(
                self::OPTION_STRICT,
                null,
                InputOption::VALUE_NONE,
                'Abort when the index cron runs or the changelog has pending entries'
            )
            ->addOption(
                self::OPTION_PROFILE,
                null,
                InputOption::VALUE_NONE,
                'Record each repeat with the Magento profiler (needs mage-os/module-profiler); '
                . 'profiled runs are left out of compare'
            );
    }

    /**
     * Profile option
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function profile(InputInterface $input): bool
    {
        return (bool)$input->getOption(self::OPTION_PROFILE);
    }

    /**
     * Notes option, null when not given
     *
     * @param InputInterface $input
     * @return string|null
     */
    protected function notes(InputInterface $input): ?string
    {
        $notes = $input->getOption(self::OPTION_NOTES);
        return $notes === null ? null : (string)$notes;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Throws for an indexer without adapter
            $this->adapterPool->get((string)$input->getOption(self::OPTION_INDEXER));
            $this->checkThreads($input);
            $request = $this->buildRequest($input, $output);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        try {
            $strict = (bool)$input->getOption(self::OPTION_STRICT);
            foreach ($this->safeguards->check($request->indexerId, $strict) as $warning) {
                $output->writeln('<comment>Warning: ' . $warning . '</comment>');
            }
            $output->writeln('<comment>' . Safeguards::TRAFFIC_WARNING . '</comment>');
            $this->printProfilerWarnings($output, $request);

            $outcomes = $this->runner->run(
                $request,
                fn (RunOutcome $outcome) => $this->printOutcome($output, $request, $outcome)
            );
        } catch (LocalizedException $e) {
            // Production refused, --strict, the indexer already working or without partial path: nothing measured
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->printMedian($output, $outcomes);
        $failed = array_filter($outcomes, static fn (RunOutcome $outcome): bool => $outcome->error !== null);
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Refuse a --threads value other than the worker count the indexers of this process will use
     *
     * @param InputInterface $input
     * @return void
     * @throws \InvalidArgumentException
     */
    private function checkThreads(InputInterface $input): void
    {
        $expected = $input->getOption(self::OPTION_THREADS);
        $actual = $this->hostInfo->getThreadsCount() ?? 1;
        if ($expected !== null && (int)$expected !== $actual) {
            throw new \InvalidArgumentException(sprintf(
                '%1$s is %2$d, --threads=%3$d expected. It is read when bin/magento starts: run '
                . '%1$s=%3$d bin/magento %4$s ...',
                HostInfo::THREADS_ENV,
                $actual,
                (int)$expected,
                $this->getName()
            ));
        }
    }

    /**
     * Say how the profiler affects this series
     *
     * @param OutputInterface $output
     * @param RunRequest $request
     * @return void
     */
    private function printProfilerWarnings(OutputInterface $output, RunRequest $request): void
    {
        if ($this->profiler->isProfilerOn()) {
            $output->writeln(
                '<comment>Warning: the Magento profiler is already on (MAGE_PROFILER or var/profiler.flag): every '
                . 'run carries its overhead and is stored as profiled, left out of compare and of label medians. '
                . 'Its report is written where that profiler writes it.</comment>'
            );
        } elseif ($request->profile && !$this->profiler->isAvailable()) {
            $output->writeln(
                '<comment>Warning: --profile needs mage-os/module-profiler installed and enabled '
                . '(composer require mage-os/module-profiler && bin/magento module:enable '
                . RunProfiler::MODULE . '). Measuring without profiling.</comment>'
            );
        } elseif ($request->profile) {
            $output->writeln(
                '<comment>Profiled runs are left out of compare and of label medians: '
                . 'the profiler slows the reindex down.</comment>'
            );
        }
    }

    /**
     * Print one stored measurement
     *
     * @param OutputInterface $output
     * @param RunRequest $request
     * @param RunOutcome $outcome
     * @return void
     */
    private function printOutcome(OutputInterface $output, RunRequest $request, RunOutcome $outcome): void
    {
        $output->writeln(sprintf(
            '#%d repeat %d/%d%s: %s%s',
            $outcome->runId,
            $outcome->repeatIndex + 1,
            $request->repeat,
            $outcome->warmup ? ' (warm-up)' : '',
            $outcome->error === null
                ? sprintf('%s %.3f s', MetricCollector::WALL_TIME, $outcome->metrics[MetricCollector::WALL_TIME] ?? 0.0)
                : '<error>failed: ' . $outcome->error . '</error>',
            $outcome->profilePath === null ? '' : sprintf(', profile in var/%s/', $outcome->profilePath)
        ));
    }

    /**
     * Print the median of the measured repeats, warm-up and failed ones left out
     *
     * @param OutputInterface $output
     * @param RunOutcome[] $outcomes
     * @return void
     */
    private function printMedian(OutputInterface $output, array $outcomes): void
    {
        $measured = array_filter(
            $outcomes,
            static fn (RunOutcome $outcome): bool => !$outcome->warmup && $outcome->error === null
        );
        $median = $this->median->ofMetrics(
            array_map(static fn (RunOutcome $outcome): array => $outcome->metrics, $measured)
        );
        $output->writeln(sprintf('Median of %d %s:', count($measured), count($measured) === 1 ? 'run' : 'runs'));
        $table = new Table($output);
        $table->setHeaders(['Metric', 'Median']);
        foreach ($median as $code => $value) {
            $table->addRow([$code, $this->format($value)]);
        }
        $table->render();
    }

    /**
     * Metric value without trailing zeros, n/a when not available
     *
     * @param float|null $value
     * @return string
     */
    private function format(?float $value): string
    {
        return $value === null ? 'n/a' : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
