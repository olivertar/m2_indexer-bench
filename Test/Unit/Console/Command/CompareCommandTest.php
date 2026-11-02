<?php
/**
 * Copyright © Orangecat. All rights reserved.
 */
declare(strict_types=1);

namespace Orangecat\IndexerBench\Test\Unit\Console\Command;

use Orangecat\IndexerBench\Console\Command\CompareCommand;
use Orangecat\IndexerBench\Model\Compare\MetricComparator;
use Orangecat\IndexerBench\Model\Compare\RunSelection;
use Orangecat\IndexerBench\Model\Compare\RunSelector;
use Orangecat\IndexerBench\Model\Fingerprint\FingerprintComparator;
use Orangecat\IndexerBench\Model\Report\ValueFormatter;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparer;
use Orangecat\IndexerBench\Model\Snapshot\SnapshotComparison;
use Orangecat\IndexerBench\Model\Report\MetricOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CompareCommandTest extends TestCase
{
    public function testReportShowsEveryMetricTheFingerprintAndTheOutputVerdict(): void
    {
        $tester = new CommandTester($this->command(
            new RunSelection('core', [12, 13, 14], true, [
                'wall_time_s' => 2431.2,
                'peak_memory_mb' => 412.0,
                'sql_queries' => null,
                'index_rows' => 8412330.0,
            ], ['catalog' => ['products' => 1]], 'full'),
            new RunSelection('boost', [19], false, [
                'wall_time_s' => 612.8,
                'peak_memory_mb' => 410.0,
                'sql_queries' => 3114.0,
                'index_rows' => 8412330.0,
            ], ['catalog' => ['products' => 1]], 'full'),
            [],
            new SnapshotComparison('core', 'boost', true, 8412330, [])
        ));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '--baseline' => 'core',
            '--candidate' => '19',
            '--strict' => true,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $exitCode, $display);
        $this->assertStringContainsString(
            'Indexer: catalog_product_price   Baseline: core (median of 3 runs)   Candidate: #19 (boost)',
            $display
        );
        $this->assertStringContainsString('Fingerprint: OK', $display);
        $this->assertMatchesRegularExpression(
            '/wall_time_s\s+\|\s+2 431\.2\s+\|\s+612\.8\s+\|\s+-74\.8%\s+\|\s+✅ improved/u',
            $display
        );
        $this->assertMatchesRegularExpression(
            '/peak_memory_mb\s+\|\s+412\s+\|\s+410\s+\|\s+-0\.5%\s+\|\s+same/',
            $display
        );
        $this->assertMatchesRegularExpression('/sql_queries\s+\|\s+n\/a\s+\|\s+3 114\s+\|\s+—\s+\|\s+n\/a/u', $display);
        $this->assertStringContainsString('Output: MATCH (8 412 330 rows)', $display);
        $this->assertStringContainsString('No regression over 10%', $display);
    }

    public function testMarkdownFormatGivesAReportReadyForDocsBenchmarks(): void
    {
        $tester = new CommandTester($this->command(
            new RunSelection('core', [12, 13, 14], true, ['wall_time_s' => 2431.2], [], 'full'),
            new RunSelection('boost', [19], false, ['wall_time_s' => 612.8], [], 'full'),
            [],
            new SnapshotComparison('core', 'boost', true, 8412330, [])
        ));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '--baseline' => 'core',
            '--candidate' => 'boost',
            '--format' => 'markdown',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(
            "## catalog_product_price: boost vs core\n"
            . "\n"
            . "Baseline: core (median of 3 runs). Candidate: #19 (boost).\n"
            . "\n"
            . "Fingerprint: OK (same catalog, database variables and versions)\n"
            . "\n"
            . "| Metric | Baseline | Candidate | Δ | Verdict |\n"
            . "|---|---:|---:|---:|---|\n"
            . "| wall_time_s | 2 431.2 | 612.8 | -74.8% | ✅ improved |\n"
            . "\n"
            . "Output: MATCH (8 412 330 rows)\n"
            . "\n"
            . "No regression over 10%\n",
            $tester->getDisplay()
        );
    }

    public function testDifferentEnvironmentsAndDifferentWorkAreWarnedAbout(): void
    {
        $tester = new CommandTester($this->command(
            new RunSelection('core', [12], true, ['wall_time_s' => 2.0], null, 'partial', 500, 42),
            new RunSelection('boost', [19], false, ['wall_time_s' => 2.0], null, 'partial', 500, 7),
            ['catalog.products: 2040 -> 24040', 'database.variables.tmp_table_size: 16777216 -> 67108864'],
            new SnapshotComparison('core', 'boost', true, 1, [])
        ));

        $tester->execute(['-i' => 'catalog_product_price', '--baseline' => 'core', '--candidate' => '19']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString(
            "Fingerprint: DIFFERS, the comparison may not hold:\n  catalog.products: 2040 -> 24040\n"
            . "  database.variables.tmp_table_size: 16777216 -> 67108864",
            $display
        );
        $this->assertStringContainsString(
            'Warning: different work: partial (500 IDs, seed 42) vs partial (500 IDs, seed 7)',
            $display
        );
    }

    /**
     * @param SnapshotComparison|\InvalidArgumentException $output
     */
    #[DataProvider('exitProvider')]
    public function testStrictFailsOnRegressionOrDifferentOutput(
        float $candidateWallTime,
        SnapshotComparison|\InvalidArgumentException $output,
        bool $strict,
        int $expectedExit,
        string $expectedText
    ): void {
        $tester = new CommandTester($this->command(
            new RunSelection('core', [12], true, ['wall_time_s' => 100.0], [], 'full'),
            new RunSelection('boost', [19], true, ['wall_time_s' => $candidateWallTime], [], 'full'),
            [],
            $output
        ));

        $exitCode = $tester->execute([
            '-i' => 'catalog_product_price',
            '--baseline' => 'core',
            '--candidate' => 'boost',
            '--threshold' => '5',
            '--strict' => $strict,
        ]);

        $this->assertSame($expectedExit, $exitCode, $tester->getDisplay());
        $this->assertStringContainsString($expectedText, $tester->getDisplay());
    }

    /**
     * @return array<string,array{float,SnapshotComparison|\InvalidArgumentException,bool,int,string}>
     */
    public static function exitProvider(): array
    {
        $match = new SnapshotComparison('core', 'boost', true, 10, []);
        $differ = new SnapshotComparison('core', 'boost', false, 9, []);
        $none = new \InvalidArgumentException('No snapshot of catalog_product_price labelled "boost"');
        return [
            'regression' => [106.0, $match, false, Command::SUCCESS, 'Regressions over 5%: wall_time_s'],
            'regression strict' => [106.0, $match, true, Command::FAILURE, 'Regressions over 5%: wall_time_s'],
            'differ' => [100.0, $differ, false, Command::SUCCESS, 'Output: DIFFER'],
            'differ strict' => [100.0, $differ, true, Command::FAILURE, 'snapshot -i catalog_product_price -l boost'
                . ' --diff=core'],
            'no snapshot strict' => [100.0, $none, true, Command::SUCCESS, 'Output: not compared (No snapshot of '
                . 'catalog_product_price labelled "boost")'],
        ];
    }

    /**
     * @param array<string,string> $input
     */
    #[DataProvider('invalidProvider')]
    public function testInvalidInputIsRejected(array $input, string $error): void
    {
        $selection = new RunSelection('core', [12], true, ['wall_time_s' => 1.0], [], 'full');
        $tester = new CommandTester($this->command($selection, $selection, [], new \InvalidArgumentException('x')));

        $exitCode = $tester->execute(['-i' => 'catalog_product_price'] + $input);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString($error, $tester->getDisplay());
    }

    /**
     * @return array<string,array{array<string,string>,string}>
     */
    public static function invalidProvider(): array
    {
        $both = ['--baseline' => 'core', '--candidate' => 'boost'];
        return [
            'missing' => [['--baseline' => 'core'], '--baseline and --candidate are required'],
            'unknown' => [['--baseline' => 'core', '--candidate' => 'nope'], 'No comparable run labelled "nope"'],
            'threshold text' => [$both + ['--threshold' => 'ten'], '--threshold must be a number of 0 or more'],
            'threshold negative' => [$both + ['--threshold' => '-5'], '--threshold must be a number of 0 or more'],
            'format' => [$both + ['--format' => 'html'], '--format must be one of: table, markdown'],
        ];
    }

    /**
     * Command over fakes
     *
     * @param RunSelection $baseline
     * @param RunSelection $candidate
     * @param string[] $fingerprintDifferences
     * @param SnapshotComparison|\InvalidArgumentException $output
     * @return CompareCommand
     */
    private function command(
        RunSelection $baseline,
        RunSelection $candidate,
        array $fingerprintDifferences,
        SnapshotComparison|\InvalidArgumentException $output
    ): CompareCommand {
        $selector = $this->createStub(RunSelector::class);
        $selector->method('select')->willReturnCallback(
            static fn (string $indexerId, string $reference): RunSelection => match ($reference) {
                'core', '12' => $baseline,
                'boost', '19' => $candidate,
                default => throw new \InvalidArgumentException('No comparable run labelled "' . $reference . '"'),
            }
        );
        $fingerprints = $this->createStub(FingerprintComparator::class);
        $fingerprints->method('differences')->willReturn($fingerprintDifferences);
        $snapshots = $this->createStub(SnapshotComparer::class);
        if ($output instanceof \InvalidArgumentException) {
            $snapshots->method('compare')->willThrowException($output);
        } else {
            $snapshots->method('compare')->willReturn($output);
        }
        return new CompareCommand(
            $selector,
            new MetricComparator(new MetricOrder()),
            $fingerprints,
            $snapshots,
            new ValueFormatter()
        );
    }
}
