<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;

/**
 * One stored benchmark run (request parameter "id"): what ran, its metrics in report order and its environment
 */
class RunDetail implements ArgumentInterface
{
    /**
     * @param RequestInterface $request
     * @param RunReader $runReader
     * @param RunExporter $exporter
     * @param MetricOrder $metricOrder
     * @param ValueFormatter $formatter
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RunReader $runReader,
        private readonly RunExporter $exporter,
        private readonly MetricOrder $metricOrder,
        private readonly ValueFormatter $formatter,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * The requested run, or null when the id is missing or unknown
     *
     * @return array<string,mixed>|null
     */
    public function getRun(): ?array
    {
        $id = (string)$this->request->getParam('id');
        $run = ctype_digit($id) ? $this->runReader->getRun((int)$id) : null;
        if ($run === null) {
            return null;
        }
        return [
            'run_id' => $run->runId,
            'indexer_id' => $run->indexerId,
            'label' => $run->label,
            'mode' => $run->mode,
            'kind' => $this->exporter->describeWork($run),
            'repeat' => $run->repeatIndex,
            'status' => $this->exporter->describeStatus($run),
            'started_at' => $run->startedAt,
            'error' => $run->error,
            'notes' => $run->notes,
            'metrics' => array_map(fn (string $code): array => [
                'code' => $code,
                'value' => $this->formatter->value($run->metrics[$code]),
            ], $this->metricOrder->sort(array_map('strval', array_keys($run->metrics)))),
            'environment' => $this->exporter->describeEnvironment($run->fingerprint ?? []),
            'export_url' => $this->url->getUrl('indexerbench/run/export', ['id' => $run->runId]),
            'compare_url' => $this->url->getUrl(
                'indexerbench/compare/index',
                ['indexer' => $run->indexerId, 'candidate' => $run->label]
            ),
        ];
    }
}
