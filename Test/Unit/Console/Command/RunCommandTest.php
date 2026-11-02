<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Orangecat\IndexerBench\Api\IndexerAdapterInterface;
use Orangecat\IndexerBench\Console\Command\RunCommand;
use Orangecat\IndexerBench\Model\Adapter\AdapterPool;
use Orangecat\IndexerBench\Model\Fingerprint\HostInfo;
use Orangecat\IndexerBench\Model\Profile\RunProfiler;
use Orangecat\IndexerBench\Model\Run\SeriesRunner;
use Orangecat\IndexerBench\Model\Run\Median;
use Orangecat\IndexerBench\Model\Run\RunOutcome;
use Orangecat\IndexerBench\Model\Run\RunRequest;
use Orangecat\IndexerBench\Model\Safeguard\Safeguards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends TestCase
{
    /**
     * @var RunRequest[] requests the runner received
     */
    private array $requests = [];

    public function testSeriesIsMeasuredAndItsMedianLeavesTheWarmUpOut(): void
    {
        $tester = new CommandTester($this->command(
            ['Changelog catalog_product_price_cl has 3 versions pending'],
            [
                new RunOutcome(101, 0, true, ['wall_time_s' => 9.0, 'sql_queries' => null], null),
                new RunOutcome(102, 1, false, ['wall_time_s' => 2.0, 'sql_queries' => null], null),
                new RunOutcome(103, 2, false, ['wall_time_s' => 3.0, 'sql_queries' => null], null),
            ]
        ));

        $exitCode = $tester->execute([
            '--indexer' => 'catalog_product_price',
            '--label' => 'core',
            '--repeat' => '3',
            '--warmup' => true,
            '--notes' => 'before boost',
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString('Changelog catalog_product_price_cl has 3 versions pending', $display);
        $this->assertStringContainsString('Measure without traffic', $display);
        $this->assertStringContainsString('#101 repeat 1/3 (warm-up): wall_time_s 9.000', $display);
        $this->assertStringContainsString('#103 repeat 3/3: wall_time_s 3.000', $display);
        $this->assertMatchesRegularExpression('/wall_time_s\s+\|\s+2\.5\s/', $display);
        $this->assertMatchesRegularExpression('/sql_queries\s+\|\s+n\/a\s/', $display);
        $request = $this->requests[0];
        $this->assertSame(
            ['catalog_product_price', 'core', 'baseline', 3, true, false, 'before boost'],
            [$request->indexerId, $request->label, $request->mode, $request->repeat, $request->warmup,
                $request->skipReset, $request->notes]
        );
    }

    public function testProfiledRunsSayWhereTheirReportIs(): void
    {
        $tester = new CommandTester($this->command([], [
            new RunOutcome(101, 0, false, ['wall_time_s' => 2.0], null, 'indexer-bench/catalog_product_price/101'),
        ]));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '--profile' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertTrue($this->requests[0]->profile);
        $this->assertStringContainsString(
            '#101 repeat 1/1: wall_time_s 2.000 s, profile in var/indexer-bench/catalog_product_price/101/',
            $display
        );
        $this->assertStringContainsString('Profiled runs are left out of compare and of label medians', $display);
    }

    /**
     * @param bool $profile
     * @param bool $available
     * @param bool $on
     * @param string $warning
     */
    #[DataProvider('profilerWarningProvider')]
    public function testProfilerStateIsWarnedAbout(bool $profile, bool $available, bool $on, string $warning): void
    {
        $tester = new CommandTester($this->command([], [], null, $available, $on));

        $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '--profile' => $profile]);

        $this->assertStringContainsString($warning, $tester->getDisplay());
    }

    /**
     * @return array<string,array{bool,bool,bool,string}>
     */
    public static function profilerWarningProvider(): array
    {
        return [
            'module missing' => [true, false, false, '--profile needs mage-os/module-profiler installed and enabled'],
            'already on' => [false, true, true, 'the Magento profiler is already on (MAGE_PROFILER'],
        ];
    }

    public function testFailedRepeatIsShownAndExitsWithFailure(): void
    {
        $tester = new CommandTester($this->command([], [
            new RunOutcome(101, 0, false, ['wall_time_s' => 2.0], null),
            new RunOutcome(102, 1, false, [], 'Deadlock found'),
        ]));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '--repeat' => '3']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('#102 repeat 2/3: failed: Deadlock found', $tester->getDisplay());
        $this->assertStringContainsString('Median of 1 run:', $tester->getDisplay());
    }

    public function testSafeguardRefusalAbortsWithoutMeasuring(): void
    {
        $safeguards = $this->createMock(Safeguards::class);
        $safeguards->expects($this->once())->method('check')->with('catalog_product_price', true)
            ->willThrowException(new LocalizedException(__('Not measuring catalog_product_price (--strict): cron')));
        $runner = $this->createMock(SeriesRunner::class);
        $runner->expects($this->never())->method('run');
        $pool = new AdapterPool(['catalog_product_price' => $this->createStub(IndexerAdapterInterface::class)]);
        $tester = new CommandTester(
            new RunCommand(
                $safeguards,
                $runner,
                $this->createStub(HostInfo::class),
                $pool,
                new Median(),
                $this->createStub(RunProfiler::class)
            )
        );

        $exitCode = $tester->execute(['-i' => 'catalog_product_price', '-l' => 'core', '--strict' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Not measuring catalog_product_price (--strict): cron',
            $tester->getDisplay()
        );
    }

    /**
     * @param array<string,string|bool> $input
     */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputIsRejectedBeforeMeasuring(array $input, ?int $threads, string $error): void
    {
        $tester = new CommandTester($this->command([], [], $threads));

        $exitCode = $tester->execute($input);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
        $this->assertSame([], $this->requests);
    }

    /**
     * @return array<string,array{array<string,string|bool>,int|null,string}>
     */
    public static function invalidInputProvider(): array
    {
        $base = ['-i' => 'catalog_product_price', '-l' => 'core'];
        return [
            'request' => [$base + ['--mode' => 'boost'], null, 'Mode must be baseline or candidate'],
            'no adapter' => [['-i' => 'design_config_grid', '-l' => 'core'], null, 'No benchmark adapter for'],
            'threads' => [
                $base + ['--threads' => '4'],
                2,
                'MAGE_INDEXER_THREADS_COUNT is 2, --threads=4 expected. It is read when bin/magento starts: run '
                . 'MAGE_INDEXER_THREADS_COUNT=4 bin/magento indexer-bench:run ...',
            ],
        ];
    }

    /**
     * Command over fakes
     *
     * @param string[] $warnings what the safeguards report
     * @param RunOutcome[] $outcomes what the runner measures
     * @param int|null $threads MAGE_INDEXER_THREADS_COUNT of this process
     * @param bool $profilerAvailable mage-os/module-profiler enabled
     * @param bool $profilerOn profiler already on for the process (MAGE_PROFILER)
     * @return RunCommand
     */
    private function command(
        array $warnings,
        array $outcomes,
        ?int $threads = null,
        bool $profilerAvailable = true,
        bool $profilerOn = false
    ): RunCommand {
        $safeguards = $this->createStub(Safeguards::class);
        $safeguards->method('check')->willReturn($warnings);
        $runner = $this->createStub(SeriesRunner::class);
        $runner->method('run')->willReturnCallback(
            function (RunRequest $request, ?callable $onRepeat) use ($outcomes): array {
                $this->requests[] = $request;
                foreach ($outcomes as $outcome) {
                    $onRepeat !== null && $onRepeat($outcome);
                }
                return $outcomes;
            }
        );
        $hostInfo = $this->createStub(HostInfo::class);
        $hostInfo->method('getThreadsCount')->willReturn($threads);
        $pool = new AdapterPool(['catalog_product_price' => $this->createStub(IndexerAdapterInterface::class)]);

        $profiler = $this->createStub(RunProfiler::class);
        $profiler->method('isAvailable')->willReturn($profilerAvailable);
        $profiler->method('isProfilerOn')->willReturn($profilerOn);

        return new RunCommand($safeguards, $runner, $hostInfo, $pool, new Median(), $profiler);
    }
}
