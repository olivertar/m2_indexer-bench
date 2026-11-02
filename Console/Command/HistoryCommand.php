<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Orangecat\IndexerBench\Model\Collector\MetricCollector;
use Orangecat\IndexerBench\Model\History\HistoryBuilder;
use Orangecat\IndexerBench\Model\History\LabelSummary;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recent runs of an indexer with the median and trend of one metric per label (SPEC 5.2)
 */
class HistoryCommand extends Command
{
    private const NAME = 'indexer-bench:history';
    private const OPTION_INDEXER = 'indexer';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_METRIC = 'metric';

    /**
     * @param HistoryBuilder $historyBuilder
     * @param MetricOrder $metricOrder
     * @param ValueFormatter $formatter
     */
    public function __construct(
        private readonly HistoryBuilder $historyBuilder,
        private readonly MetricOrder $metricOrder,
        private readonly ValueFormatter $formatter
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('List the recent runs of an indexer with the median and trend of a metric per label')
            ->addOption(self::OPTION_INDEXER, 'i', InputOption::VALUE_REQUIRED, 'Indexer ID')
            ->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_REQUIRED, 'Runs to list', '20')
            ->addOption(
                self::OPTION_METRIC,
                null,
                InputOption::VALUE_REQUIRED,
                'Metric code; the unit suffix may be left out (wall_time)',
                MetricCollector::WALL_TIME
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $indexerId = $this->indexerId($input);
            $limit = $this->limit($input);
            $metric = $this->metric((string)$input->getOption(self::OPTION_METRIC));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }
        $history = $this->historyBuilder->build($indexerId, $limit, $metric);
        if (!$history->runs) {
            $output->writeln(sprintf(
                'No run of %s stored yet: bin/magento indexer-bench:run measures one.',
                $indexerId
            ));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Indexer: %s   Metric: %s   Last %d runs',
            $indexerId,
            $metric,
            count($history->runs)
        ));
        $runs = new Table($output);
        $runs->setHeaders(['Run', 'Started (UTC)', 'Label', 'Mode', 'Kind', 'Status', $metric]);
        foreach ($history->runs as $run) {
            $runs->addRow([
                $run->runId,
                $run->startedAt,
                $run->label,
                $run->mode,
                $this->describeWork($run),
                $this->describeStatus($run),
                $this->formatter->value($run->metrics[$metric] ?? null),
            ]);
        }
        $runs->render();

        $labels = new Table($output);
        $labels->setHeaders(['Label', 'Runs', 'Median', 'Min', 'Max', 'Trend']);
        foreach ($history->labels as $summary) {
            $labels->addRow([
                $summary->label,
                $summary->runCount,
                $this->formatter->value($summary->median),
                $this->formatter->value($summary->min),
                $this->formatter->value($summary->max),
                $this->trend($summary),
            ]);
        }
        $labels->render();
        return Command::SUCCESS;
    }

    /**
     * Indexer option
     *
     * @param InputInterface $input
     * @return string
     * @throws \InvalidArgumentException when missing
     */
    private function indexerId(InputInterface $input): string
    {
        $indexerId = (string)$input->getOption(self::OPTION_INDEXER);
        if ($indexerId === '') {
            throw new \InvalidArgumentException('An indexer is required (-i)');
        }
        return $indexerId;
    }

    /**
     * Limit option
     *
     * @param InputInterface $input
     * @return int
     * @throws \InvalidArgumentException
     */
    private function limit(InputInterface $input): int
    {
        $limit = (string)$input->getOption(self::OPTION_LIMIT);
        if (!ctype_digit($limit) || (int)$limit < 1) {
            throw new \InvalidArgumentException('--limit must be a whole number of 1 or more');
        }
        return (int)$limit;
    }

    /**
     * Metric code, completing the unit suffix when it was left out
     *
     * @param string $code
     * @return string
     * @throws \InvalidArgumentException when the collector does not measure it
     */
    private function metric(string $code): string
    {
        foreach ($this->metricOrder->knownCodes() as $known) {
            if ($known === $code || preg_match('/^' . preg_quote($code, '/') . '_(s|mb|ms)$/', $known)) {
                return $known;
            }
        }
        throw new \InvalidArgumentException(sprintf(
            'Unknown metric %s. Known: %s',
            $code,
            implode(', ', $this->metricOrder->knownCodes())
        ));
    }

    /**
     * What the run reindexed, e.g. "full" or "partial (500 IDs, seed 42)"
     *
     * @param StoredRun $run
     * @return string
     */
    private function describeWork(StoredRun $run): string
    {
        if ($run->sampleSize === null) {
            return $run->kind;
        }
        return sprintf(
            '%s (%d IDs, %s)',
            $run->kind,
            $run->sampleSize,
            $run->seed === null ? 'given with --ids' : 'seed ' . $run->seed
        );
    }

    /**
     * Status, noting the runs label medians leave out
     *
     * @param StoredRun $run
     * @return string
     */
    private function describeStatus(StoredRun $run): string
    {
        $notes = array_keys(array_filter(['warm-up' => $run->warmup, 'profiled' => $run->profiled]));
        return $run->status . ($notes ? ' (' . implode(', ', $notes) . ')' : '');
    }

    /**
     * Change of the median from the previous label of the same kind: ↑ worse, ↓ better, = same
     *
     * @param LabelSummary $summary
     * @return string
     */
    private function trend(LabelSummary $summary): string
    {
        if ($summary->deltaPct === null) {
            return '—';
        }
        $arrow = match ($summary->deltaPct <=> 0.0) {
            1 => '↑',
            -1 => '↓',
            default => '=',
        };
        return $arrow . ' ' . $this->formatter->delta($summary->deltaPct);
    }
}
