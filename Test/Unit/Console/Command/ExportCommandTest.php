<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Magento\Framework\Filesystem\Driver\File;
use Orangecat\IndexerBench\Console\Command\ExportCommand;
use Orangecat\IndexerBench\Model\Export\RunExporter;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Run\RunReader;
use Orangecat\IndexerBench\Model\Run\StoredRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ExportCommandTest extends TestCase
{
    public function testRunIsPrintedAsMarkdownByDefault(): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRun')->willReturnMap([[19, $this->storedRun(19)]]);
        $tester = new CommandTester($this->command($reader, $this->createStub(File::class)));

        $exitCode = $tester->execute(['--run' => '19']);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->assertSame($this->exporter()->markdown($this->storedRun(19)), $tester->getDisplay());
    }

    public function testLastRunIsWrittenToTheOutputFileAsJson(): void
    {
        $reader = $this->createMock(RunReader::class);
        $reader->expects($this->never())->method('getRun');
        $reader->method('getLatestRun')->willReturn($this->storedRun(23));
        $file = $this->createMock(File::class);
        $file->expects($this->once())->method('filePutContents')
            ->with('docs/benchmarks/run-23.json', $this->exporter()->json($this->storedRun(23)));
        $tester = new CommandTester($this->command($reader, $file));

        $exitCode = $tester->execute(['--format' => 'json', '--output' => 'docs/benchmarks/run-23.json']);

        $this->assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->assertSame('Run #23 exported to docs/benchmarks/run-23.json', trim($tester->getDisplay()));
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidProvider')]
    public function testNothingIsExportedOnInvalidInputOrAMissingRun(array $input, int $exit, string $error): void
    {
        $reader = $this->createStub(RunReader::class);
        $reader->method('getRun')->willReturn(null);
        $reader->method('getLatestRun')->willReturn(null);
        $file = $this->createMock(File::class);
        $file->expects($this->never())->method('filePutContents');
        $tester = new CommandTester($this->command($reader, $file));

        $exitCode = $tester->execute($input + ['--output' => 'out.md']);

        $this->assertSame($exit, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
    }

    /**
     * @return array<string,array{array<string,string>,int,string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'format' => [['--run' => '19', '--format' => 'csv'], Command::INVALID, 'Unknown format csv: json|markdown'],
            'run text' => [['--run' => 'core'], Command::INVALID, '--run must be a run ID'],
            'unknown run' => [['--run' => '404'], Command::INVALID, 'No run #404 stored'],
            'no run' => [[], Command::FAILURE, 'No run stored yet: bin/magento indexer-bench:run measures one'],
        ];
    }

    /**
     * Command over a fake reader and file driver
     *
     * @param RunReader $reader
     * @param File $file
     * @return ExportCommand
     */
    private function command(RunReader $reader, File $file): ExportCommand
    {
        return new ExportCommand($reader, $this->exporter(), $file);
    }

    /**
     * Exporter with the real formatting
     *
     * @return RunExporter
     */
    private function exporter(): RunExporter
    {
        return new RunExporter(new MetricOrder(), new ValueFormatter());
    }

    /**
     * Successful full run
     *
     * @param int $runId
     * @return StoredRun
     */
    private function storedRun(int $runId): StoredRun
    {
        return new StoredRun(
            $runId,
            'catalog_product_price',
            'boost',
            'candidate',
            'full',
            0,
            false,
            false,
            'ok',
            '2026-10-31 10:00:00',
            [],
            ['wall_time_s' => 612.8]
        );
    }
}
