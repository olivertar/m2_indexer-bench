<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Orangecat\IndexerBench\Model\Compare\MetricComparator;
use Orangecat\IndexerBench\Model\Compare\MetricComparison;
use Orangecat\IndexerBench\Model\Compare\RunSelection;
use Orangecat\IndexerBench\Model\Compare\RunSelector;
use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\TableComparison;

/**
 * Baseline against candidate for one indexer (request parameters indexer, baseline, candidate, threshold), as
 * indexer-bench:compare
 */
class CompareView implements ArgumentInterface
{
    public const DEFAULT_THRESHOLD = 10.0;

    /**
     * @param RequestInterface $request
     * @param RunReader $runReader
     * @param RunSelector $selector
     * @param MetricComparator $metricComparator
     * @param FingerprintComparator $fingerprintComparator
     * @param SnapshotComparer $snapshotComparer
     * @param ValueFormatter $formatter
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunReader $runReader,
        private readonly RunSelector $selector,
        private readonly MetricComparator $metricComparator,
        private readonly FingerprintComparator $fingerprintComparator,
        private readonly SnapshotComparer $snapshotComparer,
        private readonly ValueFormatter $formatter,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * Choices of the form: indexers with runs, labels of the selected one and the selected references
     *
     * @return array<string,mixed>
     */
    public function getForm(): array
    {
        $indexers = array_map('strval', array_keys($this->runReader->getIndexers()));
        $indexer = $this->param('indexer');
        if (!in_array($indexer, $indexers, true)) {
            $indexer = $indexers[0] ?? '';
        }
        $labels = $indexer === '' ? [] : $this->runReader->getLabels($indexer);
        return [
            'indexers' => $indexers,
            'indexer' => $indexer,
            'labels' => $labels,
            'baseline' => $this->param('baseline') ?: ($labels[0] ?? ''),
            'candidate' => $this->param('candidate') ?: ($labels ? $labels[count($labels) - 1] : ''),
            'threshold' => $this->threshold(),
            'action_url' => $this->url->getUrl('indexerbench/compare/index'),
        ];
    }

    /**
     * Comparison of the submitted references, or null before the form is submitted
     *
     * @return array<string,mixed>|null
     */
    public function getResult(): ?array
    {
        $form = $this->getForm();
        if ($this->param('baseline') === '' || $this->param('candidate') === '') {
            return null;
        }
        try {
            $baseline = $this->selector->select($form['indexer'], $form['baseline']);
            $candidate = $this->selector->select($form['indexer'], $form['candidate']);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
        $comparisons = $this->metricComparator->compare($baseline->metrics, $candidate->metrics, $form['threshold']);
        $baselineWork = $this->describeWork($baseline);
        $candidateWork = $this->describeWork($candidate);

        return [
            'baseline' => $baseline->describe(),
            'candidate' => $candidate->describe(),
            'fingerprint_differences' => $this->fingerprintComparator->differences(
                $baseline->fingerprint ?? [],
                $candidate->fingerprint ?? []
            ),
            'work_warning' => $baselineWork === $candidateWork
                ? null
                : sprintf('Different work: %s vs %s', $baselineWork, $candidateWork),
            'metrics' => array_map(fn (MetricComparison $comparison): array => [
                'code' => $comparison->code,
                'baseline' => $this->formatter->value($comparison->baseline),
                'candidate' => $this->formatter->value($comparison->candidate),
                'delta' => $this->formatter->delta($comparison->deltaPct),
                'verdict' => $comparison->verdict,
            ], $comparisons),
            'regressions' => array_values(array_map(
                static fn (MetricComparison $comparison): string => $comparison->code,
                array_filter(
                    $comparisons,
                    static fn (MetricComparison $comparison): bool =>
                        $comparison->verdict === MetricComparison::REGRESSION
                )
            )),
            'threshold' => $this->formatter->value($form['threshold']),
        ] + $this->output($form['indexer'], $baseline->label, $candidate->label);
    }

    /**
     * Output verdict from the snapshots of both labels, with the tables that differ
     *
     * @param string $indexerId
     * @param string $baselineLabel
     * @param string $candidateLabel
     * @return array<string,mixed>
     */
    private function output(string $indexerId, string $baselineLabel, string $candidateLabel): array
    {
        try {
            $snapshot = $this->snapshotComparer->compare($indexerId, $baselineLabel, $candidateLabel);
        } catch (\InvalidArgumentException $e) {
            return [
                'output' => 'not compared',
                'output_rows' => null,
                'output_note' => $e->getMessage(),
                'differing_tables' => [],
            ];
        }
        return [
            'output' => $snapshot->verdict(),
            'output_rows' => $snapshot->candidateRows(),
            'output_note' => null,
            'differing_tables' => array_values(array_map(
                static fn (TableComparison $table): array => [
                    'table' => $table->table,
                    'baseline_rows' => $table->baselineRows,
                    'candidate_rows' => $table->candidateRows,
                    'note' => $table->note,
                ],
                array_filter($snapshot->tables, static fn (TableComparison $table): bool => !$table->matches)
            )),
        ];
    }

    /**
     * What the measured reindex did, e.g. "full" or "partial (500 IDs, seed 42)", as indexer-bench:compare
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
     * Trimmed request parameter
     *
     * @param string $key
     * @return string
     */
    private function param(string $key): string
    {
        return trim((string)$this->request->getParam($key));
    }

    /**
     * Regression threshold in percent, default 10
     *
     * @return float
     */
    private function threshold(): float
    {
        $value = $this->param('threshold');
        return is_numeric($value) && (float)$value >= 0 ? (float)$value : self::DEFAULT_THRESHOLD;
    }
}
