<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use Orangecat\IndexerBench\ViewModel\RunDetail;
use PHPUnit\Framework\TestCase;

class RunDetailTest extends TestCase
{
    public function testRunWithFormattedMetricsInReportOrderEnvironmentAndLinks(): void
    {
        $run = $this->detail('12')->getRun();

        $this->assertNotNull($run);
        $this->assertSame(
            [
                'run_id' => 12,
                'indexer_id' => 'catalog_product_price',
                'label' => 'boost',
                'mode' => 'candidate',
                'kind' => 'full',
                'repeat' => 1,
                'status' => 'failed',
                'started_at' => '2026-11-02 10:00:00',
                'error' => 'Deadlock found',
                'notes' => 'after toggle',
            ],
            array_intersect_key($run, array_flip([
                'run_id', 'indexer_id', 'label', 'mode', 'kind', 'repeat', 'status', 'started_at', 'error', 'notes',
            ]))
        );
        $this->assertSame(
            [['code' => 'wall_time_s', 'value' => '1 258.133'], ['code' => 'sql_queries', 'value' => 'n/a']],
            $run['metrics']
        );
        $this->assertSame(['Magento 2.4.9 Community'], $run['environment']);
        $this->assertSame('indexerbench/run/export?id=12', $run['export_url']);
        $this->assertSame(
            'indexerbench/compare/index?indexer=catalog_product_price&candidate=boost',
            $run['compare_url']
        );
    }

    public function testUnknownOrMissingRunIdGivesNoRun(): void
    {
        $this->assertNull($this->detail('99')->getRun());
        $this->assertNull($this->detail('abc')->getRun());
    }

    /**
     * Detail over one stored run, #12, for the given id request parameter
     *
     * @param string $id
     * @return RunDetail
     */
    private function detail(string $id): RunDetail
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key): ?string => $key === 'id' ? $id : null
        );
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRun')->willReturnCallback(fn (int $runId): ?StoredRun => $runId === 12 ? new StoredRun(
            12,
            'catalog_product_price',
            'boost',
            'candidate',
            'full',
            1,
            false,
            false,
            'failed',
            '2026-11-02 10:00:00',
            ['magento' => ['version' => '2.4.9', 'edition' => 'Community']],
            ['sql_queries' => null, 'wall_time_s' => 1258.133],
            'after toggle',
            null,
            null,
            'Deadlock found'
        ) : null);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params)
        );

        return new RunDetail(
            $request,
            $reader,
            new RunExporter(new MetricOrder(), new ValueFormatter()),
            new MetricOrder(),
            new ValueFormatter(),
            $url
        );
    }
}
