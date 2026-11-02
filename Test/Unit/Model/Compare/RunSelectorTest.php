<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Model\Compare;

use Orangecat\IndexerBench\Model\Compare\RunSelector;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunSelectorTest extends TestCase
{
    public function testLabelSelectsTheMedianOfItsComparableRuns(): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getComparableRuns')->willReturnMap([['catalog_product_price', 'core', [
            $this->storedRun(12, ['wall_time_s' => 3.0, 'sql_queries' => null]),
            $this->storedRun(13, ['wall_time_s' => 1.0, 'sql_queries' => null]),
            $this->storedRun(14, ['wall_time_s' => 2.0, 'sql_queries' => null]),
        ]]]);

        $selection = (new RunSelector($reader, new Median()))->select('catalog_product_price', 'core');

        $this->assertSame('core', $selection->label);
        $this->assertSame([12, 13, 14], $selection->runIds);
        $this->assertSame(['wall_time_s' => 2.0, 'sql_queries' => null], $selection->metrics);
        $this->assertSame(['catalog' => ['products' => 12]], $selection->fingerprint);
        $this->assertSame('core (median of 3 runs)', $selection->describe());
    }

    public function testRunIdSelectsThatRun(): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRun')->willReturnMap([[19, $this->storedRun(19, ['wall_time_s' => 4.0], label: 'boost')]]);

        $selection = (new RunSelector($reader, new Median()))->select('catalog_product_price', '19');

        $this->assertSame(['boost', [19], ['wall_time_s' => 4.0]], [
            $selection->label, $selection->runIds, $selection->metrics,
        ]);
        $this->assertSame('#19 (boost)', $selection->describe());
    }

    #[DataProvider('notComparableProvider')]
    public function testRunThatCannotBeComparedIsRejected(?StoredRun $run, string $error): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRun')->willReturn($run);
        $reader->method('getComparableRuns')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        (new RunSelector($reader, new Median()))->select('catalog_product_price', $run === null ? 'nothing' : '19');
    }

    /**
     * @return array<string,array{StoredRun|null,string}>
     */
    public static function notComparableProvider(): array
    {
        $run = static fn (string $indexerId = 'catalog_product_price', string $status = 'ok', bool $warmup = false,
            bool $profiled = false): StoredRun => new StoredRun(
                19,
                $indexerId,
                'core',
                'baseline',
                'full',
                0,
                $warmup,
                $profiled,
                $status,
                '2026-10-31 10:00:00',
                null,
                []
            );
        return [
            'label without runs' => [null, 'No comparable run of catalog_product_price labelled "nothing"'],
            'other indexer' => [$run('inventory'), 'Run #19 measured inventory, not catalog_product_price'],
            'failed' => [$run(status: 'failed'), 'Run #19 failed'],
            'warm-up' => [$run(warmup: true), 'Run #19 is a warm-up'],
            'profiled' => [$run(profiled: true), 'Run #19 ran with the profiler'],
        ];
    }

    /**
     * Stored successful run of catalog_product_price
     *
     * @param int $runId
     * @param array<string,float|null> $metrics
     * @param string $label
     * @return StoredRun
     */
    private function storedRun(int $runId, array $metrics, string $label = 'core'): StoredRun
    {
        return new StoredRun(
            $runId,
            'catalog_product_price',
            $label,
            'baseline',
            'full',
            0,
            false,
            false,
            'ok',
            '2026-10-31 10:00:00',
            ['catalog' => ['products' => $runId]],
            $metrics
        );
    }
}
