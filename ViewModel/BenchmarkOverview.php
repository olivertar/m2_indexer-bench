<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\History\HistoryBuilder;
use Orangecat\IndexerBench\Model\History\LabelSummary;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;

/**
 * Benchmark runs stored in this installation's database, one section per indexer, as indexer-bench:history
 */
class BenchmarkOverview implements ArgumentInterface
{
    public const METRIC = 'wall_time_s';
    private const RECENT_RUNS = 20;

    /**
     * @param RunReader $runReader
     * @param HistoryBuilder $historyBuilder
     * @param UrlInterface $url
     * @param RunExporter $exporter
     * @param ValueFormatter $formatter
     */
    public function __construct(
        private readonly RunReader $runReader,
        private readonly HistoryBuilder $historyBuilder,
        private readonly UrlInterface $url,
        private readonly RunExporter $exporter,
        private readonly ValueFormatter $formatter
    ) {
    }

    /**
     * Indexers with their label summaries and last runs, newest first
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSections(): array
    {
        $sections = [];
        foreach ($this->runReader->getIndexers() as $indexerId => $indexer) {
            $history = $this->historyBuilder->build($indexerId, self::RECENT_RUNS, self::METRIC);
            $sections[] = [
                'indexer_id' => $indexerId,
                'runs' => $indexer['runs'],
                'last_started_at' => $indexer['last_started_at'],
                'compare_url' => $this->url->getUrl('indexerbench/compare/index', ['indexer' => $indexerId]),
                'labels' => array_map(fn (LabelSummary $summary): array => [
                    'label' => $summary->label,
                    'runs' => $summary->runCount,
                    'median' => $this->formatter->value($summary->median),
                    'min' => $this->formatter->value($summary->min),
                    'max' => $this->formatter->value($summary->max),
                    'trend' => $this->formatter->delta($summary->deltaPct),
                ], $history->labels),
                'recent' => array_map(fn (StoredRun $run): array => [
                    'run_id' => $run->runId,
                    'started_at' => $run->startedAt,
                    'label' => $run->label,
                    'mode' => $run->mode,
                    'kind' => $this->exporter->describeWork($run),
                    'status' => $this->exporter->describeStatus($run),
                    'wall_time_s' => $this->formatter->value($run->metrics[self::METRIC] ?? null),
                    'url' => $this->url->getUrl('indexerbench/run/view', ['id' => $run->runId]),
                ], array_reverse($history->runs)),
            ];
        }
        return $sections;
    }
}
