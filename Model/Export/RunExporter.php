<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Model\Export;

use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\StoredRun;

/**
 * A stored run as JSON or as Markdown for docs/benchmarks/
 */
class RunExporter
{
    /**
     * @param MetricOrder $metricOrder
     * @param ValueFormatter $formatter
     */
    public function __construct(
        private readonly MetricOrder $metricOrder,
        private readonly ValueFormatter $formatter
    ) {
    }

    /**
     * JSON document with the whole run: raw metric values in report order and the full fingerprint
     *
     * @param StoredRun $run
     * @return string
     * @throws \JsonException
     */
    public function json(StoredRun $run): string
    {
        $metrics = [];
        foreach ($this->metricOrder->sort(array_map('strval', array_keys($run->metrics))) as $code) {
            $metrics[$code] = $run->metrics[$code];
        }
        return json_encode([
            'run_id' => $run->runId,
            'indexer_id' => $run->indexerId,
            'label' => $run->label,
            'mode' => $run->mode,
            'kind' => $run->kind,
            'sample_size' => $run->sampleSize,
            'seed' => $run->seed,
            'repeat_index' => $run->repeatIndex,
            'warmup' => $run->warmup,
            'profiled' => $run->profiled,
            'status' => $run->status,
            'started_at' => $run->startedAt,
            'notes' => $run->notes,
            'metrics' => $metrics,
            'fingerprint' => $run->fingerprint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Markdown section: run, metrics in report order and a summary of the environment
     *
     * @param StoredRun $run
     * @return string
     */
    public function markdown(StoredRun $run): string
    {
        $lines = [
            sprintf('## %s · %s · run #%d', $run->indexerId, $this->cell($run->label), $run->runId),
            '',
            '| Started (UTC) | Mode | Kind | Repeat | Status |',
            '|---|---|---|---:|---|',
            sprintf(
                '| %s | %s | %s | %d | %s |',
                $run->startedAt,
                $run->mode,
                $this->describeWork($run),
                $run->repeatIndex,
                $this->describeStatus($run)
            ),
            '',
            '| Metric | Value |',
            '|---|---:|',
        ];
        foreach ($this->metricOrder->sort(array_map('strval', array_keys($run->metrics))) as $code) {
            $lines[] = sprintf('| %s | %s |', $code, $this->formatter->value($run->metrics[$code]));
        }
        $environment = $this->describeEnvironment($run->fingerprint ?? []);
        if ($environment) {
            $lines = array_merge($lines, ['', 'Environment:'], array_map(
                static fn (string $line): string => '- ' . $line,
                $environment
            ));
        }
        if ($run->notes !== null && $run->notes !== '') {
            $lines = array_merge($lines, ['', 'Notes: ' . $this->cell($run->notes)]);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * What the run reindexed, e.g. "full" or "partial (500 IDs, seed 42)"
     *
     * @param StoredRun $run
     * @return string
     */
    public function describeWork(StoredRun $run): string
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
     * Status, noting warm-up and profiled runs
     *
     * @param StoredRun $run
     * @return string
     */
    public function describeStatus(StoredRun $run): string
    {
        $notes = array_keys(array_filter(['warm-up' => $run->warmup, 'profiled' => $run->profiled]));
        return $run->status . ($notes ? ' (' . implode(', ', $notes) . ')' : '');
    }

    /**
     * Lines of the environment summary; parts missing from the fingerprint are left out
     *
     * @param array<string,mixed> $fingerprint
     * @return string[]
     */
    public function describeEnvironment(array $fingerprint): array
    {
        $magento = (array)($fingerprint['magento'] ?? []);
        $database = (array)($fingerprint['database'] ?? []);
        $products = ((array)($fingerprint['catalog'] ?? []))['products'] ?? null;
        $groups = [
            [
                isset($magento['version'])
                    ? trim('Magento ' . $magento['version'] . ' ' . ($magento['edition'] ?? ''))
                    : null,
                isset($fingerprint['php']) ? 'PHP ' . $fingerprint['php'] : null,
                isset($database['version'])
                    ? trim(($database['engine'] ?? '') . ' ' . $database['version'])
                    : null,
            ],
            [
                $products !== null ? $this->formatter->value((float)$products) . ' products' : null,
                isset($fingerprint['threads']) ? $fingerprint['threads'] . ' threads' : null,
            ],
            [
                !empty($fingerprint['git_commit']) ? 'git ' . $fingerprint['git_commit'] : null,
                !empty($fingerprint['hostname']) ? 'host ' . $fingerprint['hostname'] : null,
            ],
        ];
        $lines = [];
        foreach ($groups as $parts) {
            $parts = array_filter($parts, static fn (?string $part): bool => $part !== null);
            if ($parts) {
                $lines[] = $this->cell(implode(', ', $parts));
            }
        }
        return $lines;
    }

    /**
     * Text safe inside a Markdown table or line: pipes escaped, line breaks flattened
     *
     * @param string $text
     * @return string
     */
    private function cell(string $text): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\|', ' ', ' '], $text);
    }
}
