<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\ViewModel;

use Magento\Framework\UrlInterface;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\History\History;
use Orangecat\IndexerBench\Model\History\HistoryBuilder;
use Orangecat\IndexerBench\Model\History\LabelSummary;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use Orangecat\IndexerBench\ViewModel\BenchmarkOverview;
use PHPUnit\Framework\TestCase;

class BenchmarkOverviewTest extends TestCase
{
    public function testOneSectionPerIndexerWithLabelSummariesAndRecentRuns(): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getIndexers')->willReturn([
            'catalog_product_price' => ['runs' => 6, 'last_started_at' => '2026-11-02 14:53:09'],
        ]);
        $history = $this->createMock(HistoryBuilder::class);
        $history->expects($this->once())->method('build')->with('catalog_product_price', 20, 'wall_time_s')
            ->willReturn(new History(
                [$this->storedRun(12, 'boost', true, 35.9, 500, 42)],
                [
                    new LabelSummary('core', 3, 41.7, 40.6, 42.5, null),
                    new LabelSummary('boost', 3, 35.9, 35.0, 40.8, -13.9),
                ]
            ));
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params)
        );

        $overview = new BenchmarkOverview($reader, $history, $url, $this->exporter(), new ValueFormatter());

        $sections = $overview->getSections();

        $this->assertSame(
            [[
                'indexer_id' => 'catalog_product_price',
                'runs' => 6,
                'last_started_at' => '2026-11-02 14:53:09',
                'compare_url' => 'indexerbench/compare/index?indexer=catalog_product_price',
                'labels' => [
                    ['label' => 'core', 'runs' => 3, 'median' => '41.7', 'min' => '40.6', 'max' => '42.5',
                        'trend' => '—'],
                    ['label' => 'boost', 'runs' => 3, 'median' => '35.9', 'min' => '35', 'max' => '40.8',
                        'trend' => '-13.9%'],
                ],
                'recent' => [[
                    'run_id' => 12,
                    'started_at' => '2026-11-02 10:00:00',
                    'label' => 'boost',
                    'mode' => 'candidate',
                    'kind' => 'partial (500 IDs, seed 42)',
                    'status' => 'ok (warm-up)',
                    'wall_time_s' => '35.9',
                    'url' => 'indexerbench/run/view?id=12',
                ]],
            ]],
            $sections
        );
    }

    /**
     * Real exporter: only its describe helpers are used
     *
     * @return RunExporter
     */
    private function exporter(): RunExporter
    {
        return new RunExporter(new MetricOrder(), new ValueFormatter());
    }

    /**
     * Stored run
     *
     * @param int $runId
     * @param string $label
     * @param bool $warmup
     * @param float $wallTime
     * @param int|null $sampleSize
     * @param int|null $seed
     * @return StoredRun
     */
    private function storedRun(
        int $runId,
        string $label,
        bool $warmup,
        float $wallTime,
        ?int $sampleSize,
        ?int $seed
    ): StoredRun {
        return new StoredRun(
            $runId,
            'catalog_product_price',
            $label,
            'candidate',
            $sampleSize === null ? 'full' : 'partial',
            0,
            $warmup,
            false,
            'ok',
            '2026-11-02 10:00:00',
            [],
            ['wall_time_s' => $wallTime],
            null,
            $sampleSize,
            $seed
        );
    }
}
