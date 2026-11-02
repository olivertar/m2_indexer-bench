<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Orangecat\IndexerBench\Console\Command\HistoryCommand;
use Orangecat\IndexerBench\Model\History\History;
use Orangecat\IndexerBench\Model\History\HistoryBuilder;
use Orangecat\IndexerBench\Model\History\LabelSummary;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class HistoryCommandTest extends TestCase
{
    public function testRunsAndLabelMediansWithTheirTrendAreListed(): void
    {
        $builder = $this->createMock(HistoryBuilder::class);
        $builder->expects($this->once())->method('build')->with('catalog_product_price', 20, 'wall_time_s')
            ->willReturn(new History(
                [
                    $this->storedRun(12, 'core', 'ok', false, 2431.2),
                    $this->storedRun(13, 'boost', 'ok', true, 700.0),
                    $this->storedRun(14, 'boost', 'failed', false, null),
                    $this->storedRun(15, 'boost', 'ok', false, 612.8, 500, 42),
                ],
                [
                    new LabelSummary('core', 1, 2431.2, 2431.2, 2431.2, null),
                    new LabelSummary('boost', 1, 612.8, 612.8, 612.8, -74.794505),
                ]
            ));
        $tester = new CommandTester(new HistoryCommand($builder, new MetricOrder(), new ValueFormatter()));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price']);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString(
            'Indexer: catalog_product_price   Metric: wall_time_s   Last 4 runs',
            $display
        );
        $this->assertMatchesRegularExpression(
            '/12\s+\|\s+2026-10-31 10:00:00\s+\|\s+core\s+\|\s+baseline\s+\|\s+full\s+\|\s+ok\s+\|\s+2 431\.2/',
            $display
        );
        $this->assertMatchesRegularExpression('/13\s+\|.*\|\s+boost\s+\|.*\|\s+ok \(warm-up\)\s+\|\s+700/', $display);
        $this->assertMatchesRegularExpression('/14\s+\|.*\|\s+failed\s+\|\s+n\/a/', $display);
        $this->assertMatchesRegularExpression('/15\s+\|.*\|\s+partial \(500 IDs, seed 42\)\s+\|/', $display);
        $this->assertMatchesRegularExpression(
            '/core\s+\|\s+1\s+\|\s+2 431\.2\s+\|\s+2 431\.2\s+\|\s+2 431\.2\s+\|\s+—/u',
            $display
        );
        $this->assertMatchesRegularExpression('/boost\s+\|\s+1\s+\|\s+612\.8\s+\|.*\|\s+↓ -74\.8%/u', $display);
    }

    public function testMetricMayLeaveOutItsUnitSuffix(): void
    {
        $builder = $this->createMock(HistoryBuilder::class);
        $builder->expects($this->once())->method('build')->with('catalog_product_price', 5, 'row_lock_time_ms')
            ->willReturn(new History([], []));
        $tester = new CommandTester(new HistoryCommand($builder, new MetricOrder(), new ValueFormatter()));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '--limit' => '5',
            '--metric' => 'row_lock_time',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
    }

    public function testNoStoredRunIsSaidPlainly(): void
    {
        $builder = $this->createStub(HistoryBuilder::class);
        $builder->method('build')->willReturn(new History([], []));
        $tester = new CommandTester(new HistoryCommand($builder, new MetricOrder(), new ValueFormatter()));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(
            'No run of catalog_product_price stored yet: bin/magento indexer-bench:run measures one.',
            trim($tester->getDisplay())
        );
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsRejected(array $input, string $error): void
    {
        $builder = $this->createMock(HistoryBuilder::class);
        $builder->expects($this->never())->method('build');
        $tester = new CommandTester(new HistoryCommand($builder, new MetricOrder(), new ValueFormatter()));

        $exitCode = $tester->execute($input);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
    }

    /**
     * @return array<string,array{array<string,string>,string}>
     */
    public static function invalidProvider(): array
    {
        $indexer = ['-i' => 'catalog_product_price'];
        return [
            'no indexer' => [[], 'An indexer is required (-i)'],
            'limit zero' => [$indexer + ['--limit' => '0'], '--limit must be a whole number of 1 or more'],
            'limit text' => [$indexer + ['--limit' => 'all'], '--limit must be a whole number of 1 or more'],
            'unknown metric' => [$indexer + ['--metric' => 'speed'], 'Unknown metric speed. Known: wall_time_s, '],
        ];
    }

    /**
     * Stored run with one wall time
     *
     * @param int $runId
     * @param string $label
     * @param string $status
     * @param bool $warmup
     * @param float|null $wallTime
     * @param int|null $sampleSize
     * @param int|null $seed
     * @return StoredRun
     */
    private function storedRun(
        int $runId,
        string $label,
        string $status,
        bool $warmup,
        ?float $wallTime,
        ?int $sampleSize = null,
        ?int $seed = null
    ): StoredRun {
        return new StoredRun(
            $runId,
            'catalog_product_price',
            $label,
            $label === 'core' ? 'baseline' : 'candidate',
            $sampleSize === null ? 'full' : 'partial',
            0,
            $warmup,
            false,
            $status,
            '2026-10-31 10:00:00',
            [],
            $wallTime === null ? [] : ['wall_time_s' => $wallTime],
            null,
            $sampleSize,
            $seed
        );
    }
}
