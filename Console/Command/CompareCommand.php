<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Console\Command;

use Orangecat\IndexerBench\Model\Compare\MetricComparator;
use Orangecat\IndexerBench\Model\Compare\MetricComparison;
use Orangecat\IndexerBench\Model\Compare\RunSelection;
use Orangecat\IndexerBench\Model\Compare\RunSelector;
use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compare a candidate run or label with a baseline (SPEC 5.7)
 */
class CompareCommand extends Command
{
    private const NAME = 'indexer-bench:compare';
    private const OPTION_INDEXER = 'indexer';
    private const OPTION_BASELINE = 'baseline';
    private const OPTION_CANDIDATE = 'candidate';
    private const OPTION_THRESHOLD = 'threshold';
    private const OPTION_STRICT = 'strict';
    private const OPTION_FORMAT = 'format';
    private const FORMATS = ['table', 'markdown'];

    /**
     * How each verdict is shown
     */
    private const VERDICTS = [
        MetricComparison::IMPROVED => '✅ improved',
        MetricComparison::REGRESSION => '❌ regression',
        MetricComparison::SAME => 'same',
        MetricComparison::CHANGED => '⚠ changed',
        MetricComparison::NOT_AVAILABLE => 'n/a',
    ];

    /**
     * @param RunSelector $selector
     * @param MetricComparator $metricComparator
     * @param FingerprintComparator $fingerprintComparator
     * @param SnapshotComparer $snapshotComparer
     * @param ValueFormatter $formatter
     */
    public function __construct(
        private readonly RunSelector $selector,
        private readonly MetricComparator $metricComparator,
        private readonly FingerprintComparator $fingerprintComparator,
        private readonly SnapshotComparer $snapshotComparer,
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
            ->setDescription('Compare the metrics and the output of a candidate with a baseline')
            ->addOption(self::OPTION_INDEXER, 'i', InputOption::VALUE_REQUIRED, 'Indexer ID')
            ->addOption(
                self::OPTION_BASELINE,
                null,
                InputOption::VALUE_REQUIRED,
                'Run ID, or label (median of its runs)'
            )
            ->addOption(
                self::OPTION_CANDIDATE,
                null,
                InputOption::VALUE_REQUIRED,
                'Run ID, or label (median of its runs)'
            )
            ->addOption(
                self::OPTION_THRESHOLD,
                null,
                InputOption::VALUE_REQUIRED,
                'Change in % beyond which a metric counts as improved or regressed',
                '10'
            )
            ->addOption(
                self::OPTION_STRICT,
                null,
                InputOption::VALUE_NONE,
                'Exit with 1 on any regression or when the output differs'
            )
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table|markdown (markdown is ready for docs/benchmarks/)',
                'table'
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexerId = (string)$input->getOption(self::OPTION_INDEXER);
        try {
            $threshold = $this->threshold($input);
            $markdown = $this->format($input) === 'markdown';
            [$baselineReference, $candidateReference] = $this->references($input);
            $baseline = $this->selector->select($indexerId, $baselineReference);
            $candidate = $this->selector->select($indexerId, $candidateReference);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        if ($markdown) {
            $output->writeln(sprintf('## %s: %s vs %s', $indexerId, $candidate->label, $baseline->label));
            $output->writeln('');
            $output->writeln(sprintf('Baseline: %s. Candidate: %s.', $baseline->describe(), $candidate->describe()));
            $output->writeln('');
        } else {
            $output->writeln(sprintf(
                'Indexer: %s   Baseline: %s   Candidate: %s',
                $indexerId,
                $baseline->describe(),
                $candidate->describe()
            ));
        }
        $this->printFingerprint($output, $baseline, $candidate, $markdown);

        $comparisons = $this->metricComparator->compare($baseline->metrics, $candidate->metrics, $threshold);
        $rows = array_map(fn (MetricComparison $comparison): array => [
            $comparison->code,
            $this->formatter->value($comparison->baseline),
            $this->formatter->value($comparison->candidate),
            $this->formatDelta($comparison),
            self::VERDICTS[$comparison->verdict] ?? $comparison->verdict,
        ], $comparisons);
        $this->printMetrics($output, $rows, $markdown);

        $outputMatches = $this->printOutput($output, $indexerId, $baseline, $candidate);
        if ($markdown) {
            $output->writeln('');
        }
        $regressions = array_filter(
            $comparisons,
            static fn (MetricComparison $comparison): bool => $comparison->verdict === MetricComparison::REGRESSION
        );
        $output->writeln($regressions
            ? sprintf('<error>Regressions over %s%%: %s</error>', $this->formatter->value($threshold), implode(
                ', ',
                array_map(static fn (MetricComparison $comparison): string => $comparison->code, $regressions)
            ))
            : sprintf('<info>No regression over %s%%</info>', $this->formatter->value($threshold)));
        $failed = $regressions || $outputMatches === false;
        return $failed && $input->getOption(self::OPTION_STRICT) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Metrics table, as a console table or as Markdown
     *
     * @param OutputInterface $output
     * @param array<int,array<int,string>> $rows
     * @param bool $markdown
     * @return void
     */
    private function printMetrics(OutputInterface $output, array $rows, bool $markdown): void
    {
        $headers = ['Metric', 'Baseline', 'Candidate', 'Δ', 'Verdict'];
        if (!$markdown) {
            (new Table($output))->setHeaders($headers)->setRows($rows)->render();
            return;
        }
        $output->writeln('| ' . implode(' | ', $headers) . ' |', OutputInterface::OUTPUT_RAW);
        $output->writeln('|---|---:|---:|---:|---|', OutputInterface::OUTPUT_RAW);
        foreach ($rows as $row) {
            $output->writeln('| ' . implode(' | ', $row) . ' |', OutputInterface::OUTPUT_RAW);
        }
        $output->writeln('');
    }

    /**
     * Output format option
     *
     * @param InputInterface $input
     * @return string
     * @throws \InvalidArgumentException
     */
    private function format(InputInterface $input): string
    {
        $format = (string)$input->getOption(self::OPTION_FORMAT);
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('--format must be one of: ' . implode(', ', self::FORMATS));
        }
        return $format;
    }

    /**
     * Baseline and candidate references
     *
     * @param InputInterface $input
     * @return array{string,string}
     * @throws \InvalidArgumentException when one is missing
     */
    private function references(InputInterface $input): array
    {
        $baseline = $input->getOption(self::OPTION_BASELINE);
        $candidate = $input->getOption(self::OPTION_CANDIDATE);
        if ($baseline === null || $candidate === null) {
            throw new \InvalidArgumentException('--baseline and --candidate are required');
        }
        return [(string)$baseline, (string)$candidate];
    }

    /**
     * Threshold option in %
     *
     * @param InputInterface $input
     * @return float
     * @throws \InvalidArgumentException
     */
    private function threshold(InputInterface $input): float
    {
        $value = $input->getOption(self::OPTION_THRESHOLD);
        if (!is_numeric($value) || (float)$value < 0) {
            throw new \InvalidArgumentException('--threshold must be a number of 0 or more');
        }
        return (float)$value;
    }

    /**
     * Fingerprint verdict: what differs between the environments of both sides
     *
     * @param OutputInterface $output
     * @param RunSelection $baseline
     * @param RunSelection $candidate
     * @param bool $markdown
     * @return void
     */
    private function printFingerprint(
        OutputInterface $output,
        RunSelection $baseline,
        RunSelection $candidate,
        bool $markdown
    ): void {
        $differences = $this->fingerprintComparator->differences(
            $baseline->fingerprint ?? [],
            $candidate->fingerprint ?? []
        );
        if (!$differences) {
            $output->writeln('Fingerprint: OK (same catalog, database variables and versions)');
        } else {
            $output->writeln('<comment>Fingerprint: DIFFERS, the comparison may not hold:</comment>');
            foreach ($differences as $difference) {
                $output->writeln(($markdown ? '- ' : '  ') . $difference, OutputInterface::OUTPUT_RAW);
            }
        }
        $baselineWork = $this->describeWork($baseline);
        $candidateWork = $this->describeWork($candidate);
        if ($baselineWork !== $candidateWork) {
            $output->writeln(sprintf(
                '<comment>Warning: different work: %s vs %s</comment>',
                $baselineWork,
                $candidateWork
            ));
        }
        if ($markdown) {
            $output->writeln('');
        }
    }

    /**
     * What the measured reindex did, e.g. "full" or "partial (500 IDs, seed 42)"
     *
     * @param RunSelection $selection
     * @return string
     */
    private function describeWork(RunSelection $selection): string
    {
        if ($selection->sampleSize === null) {
            return $selection->kind;
        }
        return sprintf(
            '%s (%d IDs, %s)',
            $selection->kind,
            $selection->sampleSize,
            $selection->seed === null ? 'given with --ids' : 'seed ' . $selection->seed
        );
    }

    /**
     * Output verdict from the snapshots of both labels
     *
     * @param OutputInterface $output
     * @param string $indexerId
     * @param RunSelection $baseline
     * @param RunSelection $candidate
     * @return bool|null whether the outputs match, null when a label has no snapshot
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function printOutput(
        OutputInterface $output,
        string $indexerId,
        RunSelection $baseline,
        RunSelection $candidate
    ): ?bool {
        try {
            $comparison = $this->snapshotComparer->compare($indexerId, $baseline->label, $candidate->label);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('Output: not compared (%s)', $e->getMessage()));
            return null;
        }
        if ($comparison->matches()) {
            $output->writeln(sprintf(
                '<info>Output: MATCH (%s rows)</info>',
                $this->formatter->value((float)$comparison->candidateRows())
            ));
            return true;
        }
        $output->writeln(sprintf(
            '<error>Output: DIFFER</error> (bin/magento indexer-bench:snapshot -i %s -l %s --diff=%s lists the rows)',
            $indexerId,
            $candidate->label,
            $baseline->label
        ));
        return false;
    }

    /**
     * Change in %, +∞ from zero, — when not available
     *
     * @param MetricComparison $comparison
     * @return string
     */
    private function formatDelta(MetricComparison $comparison): string
    {
        if ($comparison->deltaPct === null && $comparison->baseline === 0.0 && $comparison->candidate !== null) {
            return '+∞';
        }
        return $this->formatter->delta($comparison->deltaPct);
    }
}
