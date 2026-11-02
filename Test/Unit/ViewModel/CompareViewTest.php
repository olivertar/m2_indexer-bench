<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Orangecat\IndexerBench\Model\Compare\MetricComparator;
use Orangecat\IndexerBench\Model\Compare\MetricComparison;
use Orangecat\IndexerBench\Model\Compare\RunSelection;
use Orangecat\IndexerBench\Model\Compare\RunSelector;
use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparison;
use Orangecat\IndexerBench\Model\Snapshot\TableComparison;
use Orangecat\IndexerBench\ViewModel\CompareView;
use PHPUnit\Framework\TestCase;

class CompareViewTest extends TestCase
{
    public function testFormDefaultsToTheFirstIndexerAndItsFirstAndLastLabels(): void
    {
        $view = $this->view([]);

        $this->assertSame(
            [
                'indexers' => ['catalog_category_product', 'catalog_product_price'],
                'indexer' => 'catalog_category_product',
                'labels' => ['core', 'boost'],
                'baseline' => 'core',
                'candidate' => 'boost',
                'threshold' => 10.0,
                'action_url' => 'indexerbench/compare/index?',
            ],
            $view->getForm()
        );
        $this->assertNull($view->getResult());
    }

    public function testResultHasVerdictsEnvironmentDifferencesWorkWarningAndOutput(): void
    {
        $view = $this->view([
            'indexer' => 'catalog_product_price',
            'baseline' => 'core',
            'candidate' => 'boost',
            'threshold' => '15',
        ]);

        $this->assertSame(
            [
                'baseline' => 'core (median of 3 runs)',
                'candidate' => 'boost (median of 1 run)',
                'fingerprint_differences' => ['php: 8.5 -> 8.4'],
                'work_warning' => 'Different work: full vs partial (500 IDs, seed 42)',
                'metrics' => [
                    [
                        'code' => 'wall_time_s',
                        'baseline' => '41.7',
                        'candidate' => '35.9',
                        'delta' => '-13.9%',
                        'verdict' => 'improved',
                    ],
                    [
                        'code' => 'sql_queries',
                        'baseline' => '100',
                        'candidate' => '120',
                        'delta' => '+20.0%',
                        'verdict' => 'regression',
                    ],
                ],
                'regressions' => ['sql_queries'],
                'threshold' => '15',
                'output' => 'MATCH',
                'output_rows' => 488163,
                'output_note' => null,
                'differing_tables' => [],
            ],
            $view->getResult()
        );
    }

    public function testUnknownReferenceIsAnErrorAndDifferingTablesAreListed(): void
    {
        $unknown = $this->view(['indexer' => 'catalog_product_price', 'baseline' => 'core', 'candidate' => 'x']);
        $differ = $this->view(
            ['indexer' => 'catalog_product_price', 'baseline' => 'core', 'candidate' => 'boost'],
            new SnapshotComparison('core', 'boost', false, 10, [
                'catalog_product_index_price' => new TableComparison('catalog_product_index_price', 10, 9, false),
                'catalog_product_index_tier_price' =>
                    new TableComparison('catalog_product_index_tier_price', 3, 3, true),
            ])
        );

        $this->assertSame(['error' => 'No comparable run labelled "x"'], $unknown->getResult());
        $result = $differ->getResult();
        $this->assertSame('DIFFER', $result['output'] ?? null);
        $this->assertSame(
            [['table' => 'catalog_product_index_price', 'baseline_rows' => 10, 'candidate_rows' => 9, 'note' => null]],
            $result['differing_tables']
        );
    }

    /**
     * View over two indexers whose labels are core and boost
     *
     * @param array<string,string> $params request parameters
     * @param SnapshotComparison|null $snapshot
     * @return CompareView
     */
    private function view(array $params, ?SnapshotComparison $snapshot = null): CompareView
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key): ?string => $params[$key] ?? null
        );
        $reader = $this->createStub(RunReader::class);
        $reader->method('getIndexers')->willReturn([
            'catalog_category_product' => ['runs' => 2, 'last_started_at' => '2026-11-02 10:00:00'],
            'catalog_product_price' => ['runs' => 6, 'last_started_at' => '2026-11-02 14:53:09'],
        ]);
        $reader->method('getLabels')->willReturn(['core', 'boost']);
        $selector = $this->createStub(RunSelector::class);
        $selector->method('select')->willReturnCallback(
            static fn (string $indexerId, string $reference): RunSelection => match ($reference) {
                'core' => new RunSelection('core', [1, 2, 3], true, ['wall_time_s' => 41.7], ['php' => '8.5'], 'full'),
                'boost' => new RunSelection(
                    'boost',
                    [4],
                    true,
                    ['wall_time_s' => 35.9],
                    ['php' => '8.4'],
                    'partial',
                    500,
                    42
                ),
                default => throw new \InvalidArgumentException('No comparable run labelled "' . $reference . '"'),
            }
        );
        $metrics = $this->createStub(MetricComparator::class);
        $metrics->method('compare')->willReturn([
            new MetricComparison('wall_time_s', 41.7, 35.9, -13.9, MetricComparison::IMPROVED),
            new MetricComparison('sql_queries', 100.0, 120.0, 20.0, MetricComparison::REGRESSION),
        ]);
        $fingerprints = $this->createStub(FingerprintComparator::class);
        $fingerprints->method('differences')->willReturn(['php: 8.5 -> 8.4']);
        $snapshots = $this->createStub(SnapshotComparer::class);
        $snapshots->method('compare')->willReturn(
            $snapshot ?? new SnapshotComparison('core', 'boost', true, 488163, [])
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, ?array $params = null): string => $route . '?' . http_build_query((array)$params)
        );

        return new CompareView(
            $request,
            $reader,
            $selector,
            $metrics,
            $fingerprints,
            $snapshots,
            new ValueFormatter(),
            $url
        );
    }
}
