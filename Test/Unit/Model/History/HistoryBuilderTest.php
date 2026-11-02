<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\History;

use Orangecat\IndexerBench\Model\History\HistoryBuilder;
use Orangecat\IndexerBench\Model\History\LabelSummary;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\TestCase;

class HistoryBuilderTest extends TestCase
{
    public function testLabelsInOrderOfAppearanceWithMedianRangeAndChangeFromThePreviousLabel(): void
    {
        $recent = [
            $this->storedRun(3, 'core', 30.0),
            $this->storedRun(4, 'boost', 12.0),
            $this->storedRun(5, 'core', 20.0),
        ];
        $comparable = [
            'core' => [
                $this->storedRun(1, 'core', 10.0),
                $this->storedRun(3, 'core', 30.0),
                $this->storedRun(5, 'core', 20.0),
            ],
            'boost' => [$this->storedRun(4, 'boost', 12.0)],
        ];
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRecentRuns')->willReturn($recent);
        $reader->method('getComparableRuns')->willReturnCallback(
            static fn (string $indexerId, string $label): array => $comparable[$label]
        );

        $history = (new HistoryBuilder($reader, new Median()))->build('catalog_product_price', 20, 'wall_time_s');

        $this->assertSame($recent, $history->runs);
        $this->assertSame(
            [['core', 3, 20.0, 10.0, 30.0, null], ['boost', 1, 12.0, 12.0, 12.0, -40.0]],
            array_map(static fn (LabelSummary $summary): array => [
                $summary->label, $summary->runCount, $summary->median, $summary->min, $summary->max, $summary->deltaPct,
            ], $history->labels)
        );
    }

    public function testALabelWithoutTheMetricIsCountedButLeftOutOfTheTrend(): void
    {
        $recent = [
            $this->storedRun(1, 'core', 10.0),
            $this->storedRun(2, 'parallel', null),
            $this->storedRun(3, 'boost', 5.0),
        ];
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRecentRuns')->willReturn($recent);
        $reader->method('getComparableRuns')->willReturnCallback(
            static fn (string $indexerId, string $label): array => array_values(array_filter(
                $recent,
                static fn (StoredRun $run): bool => $run->label === $label
            ))
        );

        $history = (new HistoryBuilder($reader, new Median()))->build('catalog_product_price', 20, 'wall_time_s');

        $this->assertSame(
            [['core', 1, 10.0, null], ['parallel', 1, null, null], ['boost', 1, 5.0, -50.0]],
            array_map(static fn (LabelSummary $summary): array => [
                $summary->label, $summary->runCount, $summary->median, $summary->deltaPct,
            ], $history->labels)
        );
    }

    public function testTheTrendComparesFullAndPartialReindexesOnlyWithTheirOwnKind(): void
    {
        $recent = [
            $this->storedRun(1, 'core', 40.0),
            $this->storedRun(2, 'partial-core', 1.0, 'partial'),
            $this->storedRun(3, 'boost', 30.0),
            $this->storedRun(4, 'partial-boost', 0.5, 'partial'),
            $this->storedRun(5, 'profiled', 99.0),
        ];
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRecentRuns')->willReturn($recent);
        // Profiled runs are not comparable: that label has none
        $reader->method('getComparableRuns')->willReturnCallback(
            static fn (string $indexerId, string $label): array => array_values(array_filter(
                $recent,
                static fn (StoredRun $run): bool => $run->label === $label && $label !== 'profiled'
            ))
        );

        $history = (new HistoryBuilder($reader, new Median()))->build('catalog_product_price', 20, 'wall_time_s');

        $this->assertSame(
            [['core', null], ['partial-core', null], ['boost', -25.0], ['partial-boost', -50.0], ['profiled', null]],
            array_map(
                static fn (LabelSummary $summary): array => [$summary->label, $summary->deltaPct],
                $history->labels
            )
        );
    }

    /**
     * Stored successful run with one wall time
     *
     * @param int $runId
     * @param string $label
     * @param float|null $wallTime
     * @param string $kind
     * @return StoredRun
     */
    private function storedRun(int $runId, string $label, ?float $wallTime, string $kind = 'full'): StoredRun
    {
        return new StoredRun(
            $runId,
            'catalog_product_price',
            $label,
            'baseline',
            $kind,
            0,
            false,
            false,
            'ok',
            '2026-10-31 10:00:00',
            [],
            ['wall_time_s' => $wallTime]
        );
    }
}
